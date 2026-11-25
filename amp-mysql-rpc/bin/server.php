#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * AmpMySQL RPC 服务启动入口。
 *
 * 用法：php bin/server.php [-c config.php]
 */

use AmpMysqlRpc\Config;
use AmpMysqlRpc\LoggerFactory;
use AmpMysqlRpc\MysqlManager;
use AmpMysqlRpc\RpcServer;
use Revolt\EventLoop;

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from CLI\n");
}

require __DIR__ . '/../vendor/autoload.php';

// ---- 参数解析 ----
$configFile = __DIR__ . '/../config.php';
$args = array_slice($argv, 1);
for ($i = 0, $n = count($args); $i < $n; $i++) {
    if ($args[$i] === '-c' && isset($args[$i + 1])) {
        $configFile = $args[$i + 1];
        $i++;
    } elseif ($args[$i] === '-h' || $args[$i] === '--help') {
        echo "Usage: php bin/server.php [-c /path/to/config.php]\n";
        exit(0);
    }
}

// ---- 全局错误兜底 ----
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    fwrite(STDERR, sprintf("[warn] %s in %s:%d\n", $message, $file, $line));
    return true;
});

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

try {
    $config = Config::load($configFile);
} catch (\Throwable $e) {
    fwrite(STDERR, "[fatal] config load failed: {$e->getMessage()}\n");
    exit(1);
}

$logger = LoggerFactory::create($config->log);

// ---- 启动 ----
try {
    $mysql = new MysqlManager($config, $logger);
    $server = new RpcServer($config, $mysql, $logger);

    EventLoop::setErrorHandler(static function (\Throwable $e) use ($logger): void {
        $logger->error('event loop error: ' . $e->getMessage(), [
            'type' => 'loop', 'error' => $e->getMessage(),
        ]);
        fwrite(STDERR, '[error] event loop: ' . $e->getMessage() . "\n");
    });

    // accept 循环（在 EventLoop::run 内驱动）
    EventLoop::queue(fn () => $server->run());

    EventLoop::run();
} catch (\Throwable $e) {
    $logger->error('server crashed: ' . $e->getMessage(), ['type' => 'fatal']);
    fwrite(STDERR, "[fatal] {$e->getMessage()}\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

echo "[exit] server stopped\n";
