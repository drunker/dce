<?php
/**
 * Author: Drunk
 * Date: 2019/10/25 14:19
 */

namespace dce\sharding\parser;

use Closure;
use dce\sharding\parser\mysql\MysqlFunctionParser;
use dce\sharding\parser\mysql\MysqlFieldParser;
use dce\sharding\parser\mysql\MysqlStatementParser;
use dce\sharding\parser\mysql\MysqlValueParser;
use drunk\Utility;

abstract class MysqlParser extends SqlParser {
    protected static array $nameQuotes = ['`'];

    protected static array $columnWildcards = ['*'];

    private static array $selectModifiers = ['ALL', 'DISTINCT', 'DISTINCTROW', 'HIGH_PRIORITY', 'STRAIGHT_JOIN', 'SQL_SMALL_RESULT', 'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT', 'SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS'];

    protected function parseWithOffset(array|null $allowedStatements = []): self|null {
        $operator = $this->preParseOperator();
        if (in_array($operator, self::$partSeparators)) {
            $word = $this->preParseWord();
            $instance = $this->parseByWord($word, $allowedStatements);
        } else {
            $instance = $this->parseByOperator($operator);
        }
        return $instance;
    }

    /**
     * 根据符号的第一个部分解析处理符号
     * @param string $operator
     * @return self|null
     * @throws StatementParserException
     */
    protected function parseByOperator(string $operator): self|null {
        if ($instance =
            MysqlFieldParser::build($this->statement, $this->offset, $operator) ?:
                MysqlValueParser::build($this->statement, $this->offset, $operator)
        ){
            return $instance;
        } else {
            throw new StatementParserException("操作符'{$operator}'无效");
        }
    }

    /**
     * 根据词语的第一个部分解析完整词
     * @param string $word
     * @param array|null $allowedStatements
     * @return self|null
     * @throws StatementParserException
     */
    protected function parseByWord(string $word, array|null $allowedStatements = []): self|null {
        $instance =
            MysqlValueParser::buildByWord($this->statement, $this->offset, $word) ?:
                MysqlFunctionParser::build($this->statement, $this->offset, $word) ?:
                    MysqlStatementParser::build($this->statement, $this->offset, $word) ?:
                        MysqlFieldParser::buildByWord($this->statement, $this->offset, $word);
        if (null === $allowedStatements && $instance instanceof MysqlStatementParser || $allowedStatements && ! in_array(get_class($instance), $allowedStatements)) {
            throw new StatementParserException("语句'{$word}'出现在无效位置");
        }
        return $instance;
    }

    /**
     * 遍历语句, 供回调解析及处理
     * @param Closure|null $operatorCall    操作符回调   call($operator)
     * @param Closure|null $wordCall        单词回调    call($word)
     * @param Closure|null $followupCall    尾缀回调    call()
     * @throws StatementParserException
     */
    protected function traverse(Closure|null $operatorCall, Closure|null $wordCall, Closure|null $followupCall = null): void {
        $followupCall ??= Utility::noop();
        while ($this->offset < $this->statementLength) {
            $char = mb_substr($this->statement, $this->offset, 1);
            if ($this->isBoundary($char)) {
                // 此处解析了符号, 所以前移了偏移, 所以后续的回调中的parseString就无需再将偏移前移一位了
                $operator = $this->parseOperator($char);
                if (in_array($operator, self::$partSeparators)) {
                    continue;
                }
                $result = call_user_func_array($operatorCall, [$operator]);
            } else {
                $word = $this->parseWord($char);
                $result = call_user_func_array($wordCall, [$word]);
            }

            if (self::TRAVERSE_CALLBACK_CONTINUE === $result) {
                continue;
            } else if (self::TRAVERSE_CALLBACK_BREAK === $result) {
                break;
            }
            $result = call_user_func_array($followupCall, []);
            if (self::TRAVERSE_CALLBACK_EXCEPTION === $result) {
//                test_dump($char, $offset, '语句异常, 无法解析');
                throw new StatementParserException('语句异常, 无法解析');
            } else if (self::TRAVERSE_CALLBACK_BREAK === $result) {
                break;
            }
        }
    }

    /**
     * 预解析下个符号, 若后续无符号, 则返回false
     * @return bool|string
     */
    protected function preParseOperator(): string|false {
        $char = mb_substr($this->statement, $this->offset, 1);
        if (! $this->isBoundary($char)) {
            return false;
        }
        $operator = $this->parseOperator($char);
        return $operator;
    }

    /**
     * 预解析下个单词, 若后续非字词, 则返回false
     * @return bool|string
     */
    protected function preParseWord(): string|false {
        $char = mb_substr($this->statement, $this->offset, 1);
        if ($this->isBoundary($char)) {
            $offsetBak = $this->offset;
            $operator = $this->parseOperator($char);
            if (! in_array($operator, self::$partSeparators)) {
                $this->offset = $offsetBak;
                return false;
            }
            $char = mb_substr($this->statement, $this->offset, 1);
        }
        $word = $this->parseWord($char);
        return $word;
    }

    /**
     * 预解析修饰符
     * @return bool|string
     */
    protected function preParseModifier(): string|false {
        $offsetBak = $this->offset;
        $word = $this->preParseWord();
        if ($word && in_array(strtoupper($word), self::$selectModifiers)) {
            return $word;
        }
        $this->offset = $offsetBak;
        return false;
    }

    /**
     * 预解析别名, 若当前偏移之后有别名, 则提取并返回, 若无, 则重置偏移
     * @return string|null
     * @throws StatementParserException
     */
    protected function preParseAlias(): string|null {
        $as = null;
        $alias = null;

        $this->traverse(function ($operator) use (& $alias) {
            if (in_array($operator, self::$partSeparators)) {
                return self::TRAVERSE_CALLBACK_STEP;
            } else if (in_array($operator, self::$nameQuotes)) {
                $alias = $this->parseString($operator);
                return self::TRAVERSE_CALLBACK_BREAK;
            }
            $this->offset --; // 如果为其他符号, 则表示非别名相关符号, 则回退偏移并退出循环, 留给后续程序处理
            return self::TRAVERSE_CALLBACK_BREAK;
        }, function ($word) use (& $alias, & $as) {
            if ('AS' === strtoupper($word)) {
                $as = 'AS';
                return self::TRAVERSE_CALLBACK_STEP;
            } else {
                $alias = $word; // mark 这里还有问题, 当别名用于子查询或者join的时候, 这里还应该排除掉mysql指令词
                return self::TRAVERSE_CALLBACK_BREAK;
            }
        });

        if (! $alias && $as) {
            throw new StatementParserException('未定义别名');
        }

        return $alias;
    }

    /**
     * 提取聚合函数
     * @return MysqlFunctionParser[]
     */
    public function extractAggregates(): array {
        return [];
    }

    /**
     * 取查询列名
     * @return string
     */
    public function getSelectColumnName(): string {
        return (string) $this;
    }
}
