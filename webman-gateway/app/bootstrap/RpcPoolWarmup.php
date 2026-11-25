<?php

declare(strict_types=1);

namespace app\bootstrap;

use app\rpc\MysqlRpcClient;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * RPC 连接池预热：worker 启动即建立 AmpMySQL RPC 连接，
 * 消除首个请求的冷启动竞态（连接握手未完成时无可用连接）。
 */
class RpcPoolWarmup implements Bootstrap
{
    /**
     * @param Worker|null $worker
     * @return void
     */
    public static function start($worker): void
    {
        if (config('rpc.enable', true)) {
            MysqlRpcClient::instance();
        }
    }
}
