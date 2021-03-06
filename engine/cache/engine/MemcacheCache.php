<?php
/**
 * Author: Drunk
 * Date: 2017-1-7 23:11
 */

namespace dce\cache\engine;

use dce\base\Exception;
use memcache;
use dce\cache\Cache;

final class MemcacheCache extends Cache {
    private bool $backupOn;

    function __construct(array $config) {
        $this->config = $config;
        $this->backupOn = ! empty($config['backup_on']);
    }

    private function getInst(): memcache {
        static $instance;
        // 这里可能有问题, 需判断连接是否正常
        if (null === $instance) {
            $instance = new memcache();
            $instance->connect($this->config['host'], $this->config['port']);
        }
        return $instance;
    }

    public function exists(string|array $key): bool {
        throw new Exception('Memcache不支持exists');
    }

    public function get(string|array $key): mixed {
        $key = self::genKey($key);
        $value = $this->getInst()->get($key);
        // 如果未取到缓存, 且开启了memcache备份, 则尝试从备份中取缓存
        if (false === $value && $this->backupOn) {
            $value = $this->backupGet($key);
        }
        return false === $value ? null : $value;
    }

    public function set(string|array $key, mixed $value, int $expiry = 0): bool {
        $key = self::genKey($key);
        if ($this->backupOn) {
            $this->backupSet($key, $value, $expiry); // 如果有开启memcache备份, 则写备份缓存
        }
        return $this->getInst()->set($key, $value, MEMCACHE_COMPRESSED, $expiry);
    }

    public function touch(array|string $key, int $expiry = 0): bool {
        $value = $this->get($key);
        return false === $value ? false : $this->set($key, $value, $expiry);
    }

    public function inc(string|array $key, float $value = 1): int|float|false {
        $key = self::genKey($key);
        return $this->getInst()->increment($key, $value);
    }

    public function dec(string|array $key, float $value = 1): int|float|false {
        $key = self::genKey($key);
        return $this->getInst()->decrement($key, $value);
    }

    public function del(string|array $key): bool {
        $key = self::genKey($key);
        if ($this->backupOn) {
            $this->backupDel($key);
        }
        return $this->getInst()->delete($key);
    }

    /** @inheritDoc */
    public function clear(): void {
        $this->getInst()->flush();
    }
}