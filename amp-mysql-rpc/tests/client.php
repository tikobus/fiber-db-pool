<?php

declare(strict_types=1);

/**
 * CLI 自测脚本：直接 TCP 发 JSON-RPC 报文，验证 AmpMySQL RPC 服务。
 *
 * 覆盖场景（对应阶段 1.4）：
 *   1. 正常查询        Mysql.query + bindings
 *   2. 正常写入        Mysql.execute INSERT / UPDATE
 *   3. 异常 SQL        表不存在 → -32000；方法不存在 → -32601；参数缺失/非法 → -32602
 *   4. 超时            SLEEP() 慢查询 → -32001
 *   5. 健康检查        Mysql.ping
 *   6. 并发            同连接并发 20 请求，乱序响应按 id 匹配
 *
 * 用法：php tests/client.php [host:port] [scenario]
 * 场景：all(默认) / ping / query / execute / error / timeout / concurrent
 */

require __DIR__ . '/../vendor/autoload.php';

$addr = $argv[1] ?? '127.0.0.1:9501';
$scenario = $argv[2] ?? 'all';

/** 发送一行请求，读一行响应 */
function rpc(\Amp\Socket\ResourceSocket $socket, \Amp\ByteStream\BufferedReader $reader, array $payload): array
{
    $socket->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $line = $reader->readUntil("\n");
    /** @var array */
    return json_decode($line, true, 64, JSON_THROW_ON_ERROR);
}

function req(string $id, string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

function show(string $name, array $resp, string $expect = ''): void
{
    $ok = isset($resp['result']);
    $code = $resp['error']['code'] ?? null;
    $summary = $ok
        ? json_encode($resp['result'], JSON_UNESCAPED_UNICODE)
        : "error {$code}: " . ($resp['error']['message'] ?? '?') . ' | ' . ($resp['error']['data']['detail'] ?? '');
    if (strlen((string) $summary) > 160) {
        $summary = substr((string) $summary, 0, 160) . '...';
    }
    printf("[%-3s] %-18s %s\n", $ok ? 'OK' : 'ERR', $name, $summary);

    if ($expect !== '') {
        $pass = ($expect === 'result' && $ok)
            || ($expect !== 'result' && !$ok && (string) $code === $expect);
        printf("     %-18s expect=%s → %s\n", '', $expect, $pass ? 'PASS' : '** FAIL **');
    }
}

$main = function () use ($addr, $scenario): void {
    $socket = \Amp\Socket\connect("tcp://{$addr}");
    $reader = new \Amp\ByteStream\BufferedReader($socket);

    // 准备测试表
    $setup = rpc($socket, $reader, req('setup-1', 'Mysql.execute', [
        'sql' => 'CREATE TABLE IF NOT EXISTS rpc_test_user (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ]));
    echo "setup: ", json_encode($setup['result'] ?? $setup['error'] ?? []), "\n\n";

    if ($scenario === 'ping' || $scenario === 'all') {
        show('ping', rpc($socket, $reader, req('ping-1', 'Mysql.ping')), 'result');
    }

    if ($scenario === 'query' || $scenario === 'all') {
        show('query', rpc($socket, $reader, req('q-1', 'Mysql.query', [
            'sql' => 'SELECT id, name FROM rpc_test_user WHERE id > ? ORDER BY id LIMIT 3',
            'bindings' => [0],
        ])), 'result');

        show('query-bind', rpc($socket, $reader, req('q-2', 'Mysql.query', [
            'sql' => 'SELECT COUNT(*) AS cnt FROM rpc_test_user WHERE name = ?',
            'bindings' => ['alice'],
        ])), 'result');
    }

    if ($scenario === 'execute' || $scenario === 'all') {
        show('insert', rpc($socket, $reader, req('e-1', 'Mysql.execute', [
            'sql' => 'INSERT INTO rpc_test_user(name) VALUES (?)',
            'bindings' => ['alice-' . mt_rand(1000, 9999)],
        ])), 'result');

        show('update', rpc($socket, $reader, req('e-2', 'Mysql.execute', [
            'sql' => 'UPDATE rpc_test_user SET name = ? WHERE id = ?',
            'bindings' => ['bob', 1],
        ])), 'result');

        // 只读语义校验：Mysql.query 拒绝非 SELECT
        show('query-write-reject', rpc($socket, $reader, req('e-3', 'Mysql.query', [
            'sql' => 'DELETE FROM rpc_test_user WHERE id = -1',
        ])), '-32602');
    }

    if ($scenario === 'error' || $scenario === 'all') {
        show('bad-table', rpc($socket, $reader, req('err-1', 'Mysql.query', [
            'sql' => 'SELECT * FROM no_such_table WHERE id = ?',
            'bindings' => [1],
        ])), '-32000');

        show('bad-method', rpc($socket, $reader, req('err-2', 'Mysql.dropAll')), '-32601');

        show('no-sql', rpc($socket, $reader, [
            'jsonrpc' => '2.0', 'id' => 'err-3', 'method' => 'Mysql.query', 'params' => [],
        ]), '-32602');

        // 非法 JSON（独立短连接）
        $bad = \Amp\Socket\connect("tcp://{$addr}");
        $bad->write("{not json\n");
        $badReader = new \Amp\ByteStream\BufferedReader($bad);
        show('bad-json', json_decode($badReader->readUntil("\n"), true), '-32700');
        $bad->close();

        show('bad-binding', rpc($socket, $reader, req('err-4', 'Mysql.query', [
            'sql' => 'SELECT ?',
            'bindings' => [['array' => 'denied']],
        ])), '-32602');
    }

    if ($scenario === 'timeout' || $scenario === 'all') {
        $t0 = microtime(true);
        show('timeout', rpc($socket, $reader, req('t-1', 'Mysql.query', [
            'sql' => 'SELECT SLEEP(5) AS slept',
        ])), '-32001');
        printf("     %-18s elapsed %.2fs (expect ~2s)\n", '', microtime(true) - $t0);
    }

    if ($scenario === 'concurrent' || $scenario === 'all') {
        $n = 20;
        $t0 = microtime(true);
        for ($i = 1; $i <= $n; $i++) {
            $socket->write(json_encode(req("c-{$i}", 'Mysql.query', [
                'sql' => 'SELECT SLEEP(0.1) AS ok, ? AS i',
                'bindings' => [$i],
            ])) . "\n");
        }
        $replies = [];
        for ($i = 0; $i < $n; $i++) {
            $msg = json_decode($reader->readUntil("\n"), true);
            $replies[$msg['id']] = $msg;
        }
        $elapsed = microtime(true) - $t0;
        $okCount = count($replies);
        $serial = $n * 0.1;
        printf(
            "[%-3s] %-18s %d/%d replies in %.2fs (serial estimate ~%.1fs)\n",
            $okCount === $n ? 'OK' : 'ERR',
            'concurrent',
            $okCount,
            $n,
            $elapsed,
            $serial,
        );
        if ($elapsed > $serial * 0.9) {
            echo "     ** FAIL: no concurrency observed **\n";
        }
    }

    $socket->close();
    echo "\ndone.\n";
};

try {
    \Amp\async($main);   // 入口协程：内部挂起由事件循环驱动
    Revolt\EventLoop::run();
} catch (\Throwable $e) {
    fwrite(STDERR, "client error: {$e->getMessage()}\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
