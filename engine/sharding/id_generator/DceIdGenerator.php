<?php
/**
 * Author: Drunk (idrunk.net drunkce.com)
 * Date: 2020-12-15 16:35
 */

namespace dce\sharding\id_generator;

use ArrayAccess;
use dce\config\Config;
use dce\Dce;
use dce\event\Event;
use dce\rpc\RpcClient;
use dce\rpc\RpcServer;
use dce\rpc\RpcUtility;
use dce\sharding\id_generator\bridge\IdgRequestLocal;
use dce\sharding\id_generator\server\IdgServer;
use rpc\didg\IdgClientRpc;

final class DceIdGenerator extends Config {
    public array $clientRpcHosts = ['host' => RpcUtility::DEFAULT_TCP_HOST, 'port' => RpcUtility::DEFAULT_TCP_PORT];

    public string $clientStorage = '\dce\sharding\id_generator\bridge\IdgStorageFile';

    public string $clientStorageArg = APP_RUNTIME . 'didg/';

    public string $requester;

    public array $serverRpcHosts = [];

    public string $serverStorage = '\dce\sharding\id_generator\bridge\IdgStorageFile';

    public string $serverStorageArg = APP_COMMON . 'config/didg/data/';

    public string $serverConfigDir = APP_COMMON . 'config/didg/';

    public function __construct(array|ArrayAccess $config = []) {
        parent::__construct($config);
        Event::one(Event::AFTER_DCE_INIT, fn() => $this->prepare());
    }

    private function prepare() {
        if ($this->serverRpcHosts) {
            // 如果配了服务端RPC主机, 则表示IdgServer未部署在当前进程, 需要通过RPC调用
            $serverHosts = RpcUtility::hostsFormat($this->serverRpcHosts);
            if ($this->serverStorage) {
                // 如果配置了服务端储存器, 则表示需要自动创建一个IdgServer的RPC服务
                RpcServer::new(RpcServer::host($serverHosts[0]['host'], $serverHosts[0]['port'] ?? 0))
                    ->preload(__DIR__ . '/server/IdgServerRpc.php')->start();
            }
            RpcClient::preload($serverHosts, '\rpc\didg\IdgServerRpc');
            // 如果未配置请求器, 则设为系统默认的RPC请求器
            $this->requester ??= '\dce\sharding\id_generator\bridge\IdgRequestRpc';
        } else {
            // 如果未配置服务端RPC主机且未配置请求器, 则设为本地请求器
            $this->requester ??= '\dce\sharding\id_generator\bridge\IdgRequestLocal';
        }
        if ($this->clientRpcHosts) {
            // 如果配置了客户端RPC主机, 则表示IdgClient未部署在当前进程, 需要通过RPC调用
            $clientHosts = RpcUtility::hostsFormat($this->clientRpcHosts);
            if ($this->clientStorage) {
                // 如果配置了客户端储存器, 则表示需要自动创建一个IdgClient的RPC服务
                RpcServer::new(RpcServer::host($clientHosts[0]['host'], $clientHosts[0]['port'] ?? 0))
                    ->preload(__DIR__ . '/client/IdgClientRpc.php')->start();
            }
            RpcClient::preload($clientHosts, '\rpc\didg\IdgClientRpc');
        }
    }

    /**
     * 生成ID
     * @param string $tag
     * @param int|string $uid
     * @return int
     * @throws IdgException
     */
    public function generate(string $tag, int|string $uid = 0): int {
        if ($this->clientRpcHosts) {
            return IdgClientRpc::generate($tag, $uid);
        } else {
            return $this->getClient($tag)->generate($tag, $uid);
        }
    }

    /**
     * 生成批量ID
     * @param string $tag
     * @param int $count
     * @param int|string $uid
     * @return array
     * @throws IdgException
     */
    public function batchGenerate(string $tag, int $count, int|string $uid = 0): array {
        if ($this->clientRpcHosts) {
            return IdgClientRpc::batchGenerate($tag, $count, $uid);
        } else {
            return $this->getClient($tag)->batchGenerate($tag, $count, $uid);
        }
    }

    /**
     * 取ID生成器
     * @param string $tag
     * @return IdGenerator
     */
    public function getClient(string $tag): IdGenerator {
        static $client;
        // 如果配了rpc, 则通过rpc取客户端, 否则本地实例化客户端
        if ($this->clientRpcHosts) {
            // 如果未初始化过客户端, 或者客户端未加载过当前tag, 则尝试从远程获取
            if (! $client || ! $client->wasLoaded($tag)) {
                $client = IdgClientRpc::getClient($tag);
            }
        } else if (! $client) {
            $client = $this->newClient($tag);
        }
        return $client;
    }

    public function newClient(string $tag): IdGenerator {
        static $client;
        if (null === $client) {
            $storage = new $this->clientStorage($this->clientStorageArg);
            if (ltrim($this->requester, '\\') === IdgRequestLocal::class) {
                $request = new IdgRequestLocal($this->newServer());
            } else {
                $request = new $this->requester();
            }
            $client = IdGenerator::new($storage, $request);
        }
        if (! $client->wasLoaded($tag)) {
            $client->getBatch($tag);
        }
        return $client;
    }

    public function newServer(): IdgServer {
        static $server;
        if (null === $server) {
            $storage = new $this->serverStorage($this->serverStorageArg);
            $configDir = $this->serverConfigDir;
            $server = IdgServer::new($storage, $configDir);
        }
        return $server;
    }
}