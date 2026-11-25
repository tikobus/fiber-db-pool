<?php

declare(strict_types=1);

namespace app\controller;

use app\rpc\MysqlRpcClient;
use support\Request;
use support\Response;

/**
 * 监控指标接口（开发计划 3.3）：
 *  - RPC 请求量 / 失败率 / 超时率（网关侧统计）
 *  - RPC 耗时 P50 / P95 / P99（最近 1024 次采样）
 *  - 挂起请求数 / 活跃连接数 / 熔断状态
 *
 * 多 worker 场景：本接口返回当前处理请求的 worker 进程视角指标
 * （生产可对接 Prometheus 多实例聚合，一期以均值口径评估即可）。
 */
class StatsController
{
    public function index(Request $request): Response
    {
        $stats = MysqlRpcClient::instance()->stats();
        return json([
            'code' => 0,
            'data' => $stats,
            'worker_pid' => getmypid(),
        ]);
    }
}
