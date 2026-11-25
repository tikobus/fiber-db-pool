<?php

declare(strict_types=1);

namespace app\controller;

use app\rpc\MysqlRpcClient;
use app\rpc\RpcException;
use support\Log;
use support\Request;
use support\Response;

/**
 * 用户接口：演示 Fiber 同步风格与回调桥接两种写法。
 *
 * 安全规范（docs/03）：
 *  - 前端只传业务参数，SQL 模板由 SqlTemplates 白名单管理，禁止直传 SQL；
 *  - 写操作要求携带 X-Idempotency-Key（幂等红线）；
 *  - 控制器运行在 Fiber 中，等待 RPC 时挂起，不阻塞 worker 其它请求。
 */
class UserController
{
    /**
     * GET /user?id=1
     * Fiber 同步风格（推荐）：代码顺序执行，底层挂起等待 RPC 响应。
     */
    public function get(Request $request): Response
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return $this->err(400, 'param id required');
        }

        try {
            $result = MysqlRpcClient::instance()->querySync('user.get_by_id', [$id]);
        } catch (RpcException $e) {
            Log::channel('default')->warning('user.get failed', [
                'type' => 'http', 'path' => '/user', 'request_id' => $e->requestId,
                'error' => $e->getMessage(), 'status' => $e->httpStatus,
            ]);
            return $this->err($e->httpStatus, $e->getMessage());
        }

        $rows = $result['rows'] ?? [];
        if ($rows === []) {
            return $this->err(404, "user {$id} not found");
        }

        return json([
            'code' => 0,
            'data' => $rows[0],
            'cost_ms' => $result['cost_ms'] ?? null,
        ]);
    }

    /**
     * GET /users?after=0&limit=10
     * 回调桥接风格：显式使用回调式 API + wait() 桥（计划 2.2 原始形态）。
     */
    public function list(Request $request): Response
    {
        $after = max(0, (int) $request->input('after', 0));
        $limit = min(100, max(1, (int) $request->input('limit', 10)));
        $rpc = MysqlRpcClient::instance();

        try {
            // 回调式 API（onOk / onErr 双回调）经 wait() 桥接为同步写法
            $result = $rpc->wait(
                fn (callable $onOk, callable $onErr) => $rpc->query('user.list', [$after, $limit], $onOk, $onErr)
            );
        } catch (RpcException $e) {
            return $this->err($e->httpStatus, $e->getMessage());
        }

        return json([
            'code' => 0,
            'data' => $result['rows'] ?? [],
            'cost_ms' => $result['cost_ms'] ?? null,
        ]);
    }

    /**
     * POST /user {"name": "alice"} + X-Idempotency-Key
     * 写操作：RPC 层禁止自动重试；幂等由业务 key 保证。
     */
    public function create(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $idemKey = trim((string) ($request->header('x-idempotency-key') ?? ''));

        if ($name === '' || strlen($name) > 64) {
            return $this->err(400, 'param name required (1-64 chars)');
        }
        if ($idemKey === '') {
            return $this->err(400, 'X-Idempotency-Key required for write operations');
        }

        try {
            // 幂等：同名已存在则返回既有记录（演示级判等；生产应按 key 查幂等表）
            $dup = MysqlRpcClient::instance()->querySync('user.count_by_name', [$name]);
            if (($dup['rows'][0]['cnt'] ?? 0) > 0) {
                $exists = MysqlRpcClient::instance()->querySync('user.get_by_name', [$name]);
                return json([
                    'code' => 0,
                    'data' => $exists['rows'][0] ?? null,
                    'idempotent' => true,
                ]);
            }

            $result = MysqlRpcClient::instance()->executeSync('user.create', [$name]);
        } catch (RpcException $e) {
            return $this->err($e->httpStatus, $e->getMessage());
        }

        Log::channel('default')->info('user created', [
            'type' => 'http', 'path' => '/user', 'idem_key' => $idemKey,
            'insert_id' => $result['last_insert_id'] ?? null,
        ]);

        return json([
            'code' => 0,
            'data' => [
                'id' => $result['last_insert_id'] ?? null,
                'name' => $name,
                'affected_rows' => $result['affected_rows'] ?? 0,
            ],
            'cost_ms' => $result['cost_ms'] ?? null,
        ]);
    }

    /**
     * GET /health：网关存活 + RPC 链路探活（压测前置检查与告警探针）
     */
    public function health(Request $request): Response
    {
        $rpc = MysqlRpcClient::instance();
        try {
            $result = $rpc->querySync('sys.ping', []);
            $mysqlUp = (($result['rows'][0]['ok'] ?? null) === 1)
                || ((int) ($result['rows'][0]['ok'] ?? 0) === 1);
        } catch (RpcException $e) {
            return new Response(503, ['Content-Type' => 'application/json'], json_encode([
                'code' => -1, 'gateway' => 'up', 'mysql' => 'down',
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return json([
            'code' => 0,
            'gateway' => 'up',
            'mysql' => $mysqlUp ? 'up' : 'unknown',
            'pending' => $rpc->pendingCount(),
        ]);
    }

    private function err(int $status, string $msg): Response
    {
        // webman 的 json() 助手固定 200，状态码需显式构造 Response
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'code' => -1,
            'msg' => $msg,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
