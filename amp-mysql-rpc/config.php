<?php

declare(strict_types=1);

/**
 * AmpMySQL RPC 服务配置（基准值，环境变量可覆盖）。
 *
 * 环境变量：RPC_LISTEN / MYSQL_HOST / MYSQL_PORT / MYSQL_USER / MYSQL_PASSWORD
 *          / MYSQL_DATABASE / MYSQL_MAX_CONNECTIONS / SQL_TIMEOUT / MAX_CONCURRENCY
 */
return [
    // RPC 服务监听地址（内网）
    'listen' => 'tcp://0.0.0.0:9501',

    'mysql' => [
        'host'            => '127.0.0.1',
        'port'            => 3306,
        'user'            => 'root',
        'password'        => 'root',
        'database'        => 'test',
        'charset'         => 'utf8mb4',
        'max_connections' => 16,   // 连接池上限，须小于 MySQL max_connections
        'idle_timeout'    => 60,   // 空闲连接回收秒数
    ],

    'limits' => [
        'sql_timeout'     => 2.0,  // 单条 SQL 执行超时（秒）< RPC 总超时 3s
        'max_concurrency' => 64,   // 并发 SQL 槽位（限流保护 MySQL）
        'queue_timeout'   => 0.5,  // 槽位排队超时（秒）→ -32002
        'read_retry'      => 1,    // 只读 SQL 瞬态错误重试次数
        'slow_sql_ms'     => 500,  // 慢 SQL 日志阈值（毫秒）
    ],

    'log' => [
        'file'         => 'logs/rpc.log', // 相对 bin/server.php 运行目录，可为空串（仅 stderr）
        'level'        => 'info',
        'max_line_len' => 2048,
    ],
];
