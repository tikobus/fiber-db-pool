<?php

declare(strict_types=1);

namespace AmpMysqlRpc;

/**
 * 配置加载：config.php 为基准，环境变量覆盖。
 */
final class Config
{
    /**
     * @param array{listen:string} ... 见 config.php 结构
     */
    public function __construct(
        public readonly string $listen,
        public readonly array $mysql,
        public readonly array $limits,
        public readonly array $log,
    ) {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("config file not found: {$file}");
        }

        /** @var array $raw */
        $raw = require $file;
        $raw = array_merge([
            'listen' => 'tcp://0.0.0.0:9501',
            'mysql' => [],
            'limits' => [],
            'log' => [],
        ], $raw);

        $mysql = array_merge([
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'password' => '',
            'database' => 'test',
            'charset' => 'utf8mb4',
            'max_connections' => 16,
            'idle_timeout' => 60,
        ], $raw['mysql']);

        $limits = array_merge([
            'sql_timeout' => 2.0,
            'max_concurrency' => 64,
            'queue_timeout' => 0.5,
            'read_retry' => 1,
            'slow_sql_ms' => 500,
        ], $raw['limits']);

        $log = array_merge([
            'file' => 'logs/rpc.log',
            'level' => 'info',
            'max_line_len' => 2048,
        ], $raw['log']);

        // 环境变量覆盖（部署友好）
        $envMap = [
            'RPC_LISTEN' => ['listen', 's'],
            'MYSQL_HOST' => ['mysql.host', 's'],
            'MYSQL_PORT' => ['mysql.port', 'i'],
            'MYSQL_USER' => ['mysql.user', 's'],
            'MYSQL_PASSWORD' => ['mysql.password', 's'],
            'MYSQL_DATABASE' => ['mysql.database', 's'],
            'MYSQL_MAX_CONNECTIONS' => ['mysql.max_connections', 'i'],
            'SQL_TIMEOUT' => ['limits.sql_timeout', 'f'],
            'MAX_CONCURRENCY' => ['limits.max_concurrency', 'i'],
        ];
        foreach ($envMap as $env => [$path, $type]) {
            $val = getenv($env);
            if ($val === false || $val === '') {
                continue;
            }
            $ref = &self::pathRef($raw, $path);
            $ref = match ($type) {
                'i' => (int) $val,
                'f' => (float) $val,
                default => (string) $val,
            };
            // 上面修改的是 $raw，同步到各分组
        }
        // 重新归并（env 已写入 raw）
        $mysql = array_merge($mysql, $raw['mysql']);
        $limits = array_merge($limits, $raw['limits']);

        return new self(
            listen: (string) ($raw['listen'] ?? 'tcp://0.0.0.0:9501'),
            mysql: $mysql,
            limits: $limits,
            log: $log,
        );
    }

    /** @param mixed[] $arr @return mixed */
    private static function &pathRef(array &$arr, string $dotPath)
    {
        $parts = explode('.', $dotPath);
        $cursor = &$arr;
        foreach ($parts as $p) {
            if (!isset($cursor[$p]) || !is_array($cursor[$p])) {
                $cursor[$p] = $cursor[$p] ?? null;
            }
            $cursor = &$cursor[$p];
        }
        return $cursor;
    }

    public function sqlTimeout(): float
    {
        return (float) $this->limits['sql_timeout'];
    }

    public function maxConcurrency(): int
    {
        return (int) $this->limits['max_concurrency'];
    }

    public function queueTimeout(): float
    {
        return (float) $this->limits['queue_timeout'];
    }

    public function readRetry(): int
    {
        return (int) $this->limits['read_retry'];
    }

    public function slowSqlMs(): int
    {
        return (int) $this->limits['slow_sql_ms'];
    }
}
