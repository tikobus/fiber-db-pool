<?php

declare(strict_types=1);

/**
 * 简单压测脚本：N 并发连接 × M 请求，输出 QPS / 失败率 / P95。
 *
 * 用法：php tests/bench.php [addr] [connections] [perConn] [sql]
 */

require __DIR__ . '/../vendor/autoload.php';

$addr = $argv[1] ?? '127.0.0.1:9501';
$conns = (int) ($argv[2] ?? 50);
$perConn = (int) ($argv[3] ?? 20);
$sql = $argv[4] ?? 'SELECT id, name FROM rpc_test_user WHERE id > ? LIMIT 10';

use function Amp\async;

$main = function () use ($addr, $conns, $perConn, $sql): void {
    echo "benchmark: {$conns} connections x {$perConn} reqs, sql: {$sql}\n";

    $t0 = microtime(true);
    $futures = [];
    $stats = ['ok' => 0, 'fail' => 0, 'latencies' => []];

    for ($c = 0; $c < $conns; $c++) {
        $futures[] = async(function () use ($addr, $perConn, $sql, &$stats): void {
            $socket = \Amp\Socket\connect("tcp://{$addr}");
            $reader = new \Amp\ByteStream\BufferedReader($socket);

            for ($i = 0; $i < $perConn; $i++) {
                $t = microtime(true);
                $socket->write(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => "bench-{$i}",
                    'method' => 'Mysql.query',
                    'params' => ['sql' => $sql, 'bindings' => [0]],
                ]) . "\n");

                $line = $reader->readUntil("\n");
                $stats['latencies'][] = (microtime(true) - $t) * 1000;
                $msg = json_decode($line, true);
                if (isset($msg['result'])) {
                    $stats['ok']++;
                } else {
                    $stats['fail']++;
                }
            }

            $socket->close();
        });
    }

    foreach ($futures as $f) {
        $f->await();
    }

    $elapsed = microtime(true) - $t0;
    $total = $stats['ok'] + $stats['fail'];
    $lats = $stats['latencies'];
    sort($lats);
    $p = fn (float $q): string => sprintf('%.1fms', $lats[(int) min(count($lats) - 1, (int) (count($lats) * $q))] ?? 0);

    printf(
        "total=%d ok=%d fail=%d (%.2f%%) elapsed=%.2fs QPS=%.0f lat: p50=%s p95=%s p99=%s\n",
        $total,
        $stats['ok'],
        $stats['fail'],
        $total > 0 ? $stats['fail'] / $total * 100 : 0,
        $elapsed,
        $elapsed > 0 ? $total / $elapsed : 0,
        $p(0.50),
        $p(0.95),
        $p(0.99),
    );
};

\Amp\async($main);
Revolt\EventLoop::run();
