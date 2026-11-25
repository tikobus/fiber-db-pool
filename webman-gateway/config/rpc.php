<?php

/**
 * AmpMySQL RPC 客户端配置（对应开发计划阶段 2 / 3）
 */

return [
    // RPC 总超时（秒）：必须大于 Amp 侧 sql_timeout(2s)，留网络余量
    'timeout' => 3.0,

    // AmpMySQL RPC 服务地址（docker-compose 内用服务名 mysql-rpc）
    'address' => getenv('RPC_ADDRESS') ?: 'tcp://127.0.0.1:9501',

    // 每个 worker 进程的 RPC 连接数
    'pool_size' => 4,

    // 连接上下文（SSL 等，默认空）
    'context' => [],

    // 断线重连：指数退避 [min, max]（秒）
    'reconnect_min' => 0.2,
    'reconnect_max' => 5.0,

    // 简易熔断：滑动窗口失败率触发
    'breaker' => [
        'window'       => 20,    // 统计窗口请求数
        'fail_rate'    => 0.5,   // 失败率阈值（50%）
        'cooldown'     => 5.0,   // 熔断后冷却期（秒），之后半开探测
        'half_open_ok' => 3,     // 半开态连续成功 N 次后闭合
    ],

    // 回滚开关：false 时走同步 PDO 降级路径（保留原有同步方案）
    'enable' => (bool) (getenv('RPC_ENABLE') ?: true),
];
