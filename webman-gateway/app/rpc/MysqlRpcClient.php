<?php

declare(strict_types=1);

namespace app\rpc;

use app\rpc\protocol\JsonNL;
use PDO;
use RuntimeException;
use support\Log;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

/**
 * AmpMySQL 异步 JSON-RPC TCP 客户端。
 *
 * 设计（对应开发计划阶段 2）：
 *  - 每个 worker 进程持有一个单例，内部维护 N 条 AsyncTcpConnection（JsonNL 协议）
 *  - 回调式核心 API：query() / execute()，请求按 id 写入 pending 表，
 *    响应乱序到达后按 id 匹配回调
 *  - RPC 总超时 3s（Timer 兜底）：超时触发失败回调并清理 pending，防悬挂/fd 泄漏
 *  - 断线自动重连（指数退避 0.2s→5s；连接失败与关闭均会触发 onClose 统一处理）
 *  - 简易熔断：滑动窗口失败率超阈值时快速拒绝，冷却后半开探测恢复
 *  - SQL 模板白名单：前端只传模板名 + 参数，禁止直接下发 SQL（安全红线）
 *  - 回滚开关（rpc.enable=false）：退化为同步 PDO，保留原有同步方案逃生通道
 *
 * 上层封装：
 *  - wait() 用 Fiber::suspend / $fiber->resume 桥接回调，controller 内同步写法。
 *    workerman 的 Events\Fiber 事件循环把每个事件回调放入独立 Fiber（纯 PHP
 *    用户态协程，不依赖 Swoole/Swow），挂起的 HTTP 请求 Fiber 由 RPC 响应
 *    事件回调内联恢复，天然解决“回调地狱”。
 */
class MysqlRpcClient
{
    /** @var self|null 进程级单例 */
    private static ?self $instance = null;

    /** @var array<int, AsyncTcpConnection> 活跃连接池（key: spl_object_id） */
    private array $connections = [];

    /**
     * @var array<string, array{
     *     onOk: callable, onErr: callable, timer: int, t0: float, conn: AsyncTcpConnection
     * }> 挂起请求表（key: requestId）
     */
    private array $pending = [];

    private int $nextId = 0;
    private string $idPrefix = '';

    /** @var PDO|null 回滚模式（rpc.enable=false）下的同步连接 */
    private ?PDO $pdo = null;

    // ---- 熔断状态 ----
    private int $failCount = 0;
    private int $successCount = 0;
    private bool $breakerOpen = false;
    private float $breakerOpenedAt = 0.0;

    // ---- 监控指标（计划 3.3：请求量/失败率/超时率/耗时 P95） ----
    private int $mTotal = 0;
    private int $mOk = 0;
    private int $mErr = 0;
    private int $mTimeout = 0;
    /** @var int[] 最近 N 次成功请求耗时（ms）采样 */
    private array $mLatency = [];
    private float $startedAt = 0.0;

    private const LATENCY_SAMPLES = 1024;

    private function __construct(
        private readonly array $config,
    ) {
        // 全局唯一 requestId 前缀：pid（进程级唯一，跨 worker 不碰撞）
        $this->idPrefix = getmypid() . '-';
        $this->startedAt = microtime(true);

        // 回滚模式：同步 PDO，不建立 RPC 连接
        if (!($config['enable'] ?? true)) {
            $this->pdo = $this->createPdo();
            return;
        }

        foreach (range(1, max(1, (int) ($config['pool_size'] ?? 4))) as $i) {
            $this->spawnConnection();
        }
    }

    /** 进程级单例 */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self((array) config('rpc', []));
        }
        return self::$instance;
    }

    // ------------------------------------------------------------------
    //  回调式核心 API（计划 2.2 要求的原始形态）
    // ------------------------------------------------------------------

    /**
     * 只读查询（服务端瞬态错误可安全重试 1 次，由 RPC 服务端内部完成）。
     *
     * @param string $template SQL 模板名（白名单）
     * @param array $bindings 预编译参数（按 ? 占位符顺序）
     * @param callable $onOk function (array $result, int $costMs): void
     * @param callable $onErr function (RpcException $e): void
     */
    public function query(string $template, array $bindings, callable $onOk, callable $onErr): void
    {
        $this->call('Mysql.query', $template, $bindings, $onOk, $onErr);
    }

    /**
     * 写操作（禁止自动重试；幂等性由业务侧 X-Idempotency-Key 保证）。
     */
    public function execute(string $template, array $bindings, callable $onOk, callable $onErr): void
    {
        $this->call('Mysql.execute', $template, $bindings, $onOk, $onErr);
    }

    // ------------------------------------------------------------------
    //  Fiber 同步风格封装（上层业务推荐用法）
    // ------------------------------------------------------------------

    /**
     * 同步风格只读查询：挂起当前 Fiber，RPC 回调恢复。
     * @return array{rows: list<array<string, mixed>>, cost_ms: int}
     * @throws RpcException
     */
    public function querySync(string $template, array $bindings): array
    {
        return $this->wait(fn (callable $ok, callable $err) => $this->query($template, $bindings, $ok, $err));
    }

    /**
     * 同步风格写操作。
     * @return array{affected_rows: int, last_insert_id: int|null, cost_ms: int}
     * @throws RpcException
     */
    public function executeSync(string $template, array $bindings): array
    {
        return $this->wait(fn (callable $ok, callable $err) => $this->execute($template, $bindings, $ok, $err));
    }

    /**
     * 通用回调 → Fiber 桥。
     *
     * 兼容两种回调时机：
     *  - 延迟回调（正常路径）：RPC 响应/超时在其它事件上下文到达，fiber 已挂起 → resume；
     *  - 内联回调（快速失败路径）：$op 在当前 fiber 内同步失败（模板不存在/熔断开启/
     *    无可用连接），fiber 仍在运行 → 暂存结果，不 suspend 直接返回。
     *
     * @template T
     * @param callable(callable(T): void, callable(RpcException): void): void $op
     * @return T
     * @throws RpcException
     */
    public function wait(callable $op): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new RpcException(
                'wait() must run inside a fiber (use Events\Fiber event loop)',
                RpcException::GATEWAY,
            );
        }

        $done = false;
        $ret = null;
        $op(
            function (mixed $result) use ($fiber, &$done, &$ret): void {
                if ($done) {
                    return; // 双重回调守卫（超时后迟到的响应直接丢弃）
                }
                $done = true;
                if ($fiber->isSuspended()) {
                    $fiber->resume(['ok', $result]); // 挂起态：延迟回调到达，恢复
                } else {
                    $ret = ['ok', $result]; // 运行态：内联完成，暂存待 suspend 前返回
                }
            },
            function (RpcException $e) use ($fiber, &$done, &$ret): void {
                if ($done) {
                    return;
                }
                $done = true;
                if ($fiber->isSuspended()) {
                    $fiber->resume(['err', $e]); // 挂起态：延迟回调到达，恢复
                } else {
                    $ret = ['err', $e]; // 运行态：内联快速失败，暂存待 suspend 前返回
                }
            },
        );

        // $op 同步快速失败（模板不存在/熔断/无连接）时无需挂起
        if (!$done) {
            $ret = \Fiber::suspend();
        }

        if (is_array($ret) && $ret[0] === 'err') {
            /** @var RpcException $e */
            $e = $ret[1];
            throw $e;
        }
        return $ret[1] ?? null;
    }

    // ------------------------------------------------------------------
    //  内部实现
    // ------------------------------------------------------------------

    private function call(
        string $method,
        string $template,
        array $bindings,
        callable $onOk,
        callable $onErr,
    ): void {
        $id = $this->idPrefix . (++$this->nextId);

        // SQL 模板解析（白名单）
        try {
            $sql = SqlTemplates::resolve($template);
        } catch (RuntimeException $e) {
            $this->safeErr($onErr, new RpcException($e->getMessage(), RpcException::GATEWAY, null, $id));
            return;
        }

        // 回滚模式：同步 PDO 直查（阻塞 worker，仅在降级逃生时使用）
        if ($this->pdo !== null) {
            $t0 = microtime(true);
            try {
                $result = $this->pdoQuery($method, $sql, $bindings);
                $this->safeOk($onOk, $result, (int) round((microtime(true) - $t0) * 1000));
            } catch (Throwable $e) {
                $this->safeErr($onErr, new RpcException('pdo fallback: ' . $e->getMessage(), RpcException::RPC_ERROR, null, $id));
            }
            return;
        }

        // 熔断检查
        if ($e = $this->checkBreaker()) {
            $this->safeErr($onErr, $e);
            return;
        }

        // 取一条可用连接（活跃池轮询）
        $conn = $this->pickConnection();
        if ($conn === null) {
            $this->mErr++;
            $this->markFailure();
            $this->safeErr($onErr, new RpcException(
                'rpc service unavailable (no live connection)',
                RpcException::UNAVAILABLE,
                null,
                $id,
            ));
            return;
        }

        // RPC 总超时兜底（防连接悬挂/fd 泄漏）
        $timeout = (float) ($this->config['timeout'] ?? 3.0);
        $t0 = microtime(true);
        $this->mTotal++;
        $timerId = Timer::add($timeout, function () use ($id, $onErr, $t0): void {
            $ctx = $this->pending[$id] ?? null;
            if ($ctx === null) {
                return; // 已正常完成
            }
            unset($this->pending[$id]);
            $this->mTimeout++;
            $this->markFailure();
            $this->safeErr($onErr, new RpcException(
                sprintf('rpc timeout after %.1fs (request %s)', microtime(true) - $t0, $id),
                RpcException::TIMEOUT,
                null,
                $id,
            ));
        }, [], false);

        $this->pending[$id] = [
            'onOk' => $onOk,
            'onErr' => $onErr,
            'timer' => $timerId,
            't0' => $t0,
            'conn' => $conn,
        ];

        // 注意：连接已设置 JsonNL 协议，send() 接收数组由协议层编码，勿预编码
        $conn->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => ['sql' => $sql, 'bindings' => array_values($bindings)],
        ]);

        // 请求日志（requestId 打通网关 ↔ RPC 两侧链路）
        Log::channel('default')->info('rpc call', [
            'type' => 'rpc_call', 'request_id' => $id, 'method' => $method,
            'template' => $template, 'bind' => $this->briefBindings($bindings),
        ]);
    }

    /** 响应分发：按 id 匹配 pending，清理定时器后回调 */
    private function onResponse(array $msg): void
    {
        $id = (string) ($msg['id'] ?? '');
        $ctx = $this->pending[$id] ?? null;
        if ($ctx === null) {
            return; // 超时后迟到响应 / 异常报文，静默丢弃
        }

        unset($this->pending[$id]);
        Timer::del($ctx['timer']);

        $costMs = (int) round((microtime(true) - $ctx['t0']) * 1000);

        if (isset($msg['error'])) {
            $code = (int) ($msg['error']['code'] ?? 0);
            $detail = (string) ($msg['error']['data']['detail'] ?? $msg['error']['message'] ?? '');
            $this->mErr++;
            $this->markFailure();

            // RPC 错误码 → HTTP 语义（计划 2.2）
            $status = match ($code) {
                -32001 => RpcException::TIMEOUT,       // SQL 超时 → 504
                -32002 => RpcException::UNAVAILABLE,   // 过载 → 503
                -32003 => RpcException::UNAVAILABLE,   // MySQL 不可达 → 503
                default => RpcException::RPC_ERROR,    // SQL 失败等 → 500
            };
            $this->safeErr($ctx['onErr'], new RpcException(
                "rpc error {$code}: {$detail}",
                $status,
                $code,
                $id,
            ));
            return;
        }

        $this->markSuccess();
        $this->mOk++;
        $this->sampleLatency($costMs);
        $this->safeOk($ctx['onOk'], $msg['result'] ?? [], $costMs);
    }

    private function pickConnection(): ?AsyncTcpConnection
    {
        if ($this->connections === []) {
            return null;
        }
        // 轮询取一条
        $key = array_key_first($this->connections);
        return $this->connections[$key];
    }

    /** 建立一条 RPC 连接并挂载回调 */
    private function spawnConnection(): void
    {
        $address = (string) ($this->config['address'] ?? 'tcp://127.0.0.1:9501');
        $reconnectMin = (float) ($this->config['reconnect_min'] ?? 0.2);
        $reconnectMax = (float) ($this->config['reconnect_max'] ?? 5.0);
        $reconnectDelay = $reconnectMin;

        $conn = new AsyncTcpConnection($address, (array) ($this->config['context'] ?? []));
        // JsonNL 协议（换行分包，与服务端一致）
        $conn->protocol = JsonNL::class;

        $conn->onConnect = function (AsyncTcpConnection $conn): void {
            $this->connections[spl_object_id($conn)] = $conn;
            Log::channel('default')->info('rpc connected', [
                'type' => 'rpc_connect', 'address' => $conn->getRemoteAddress(),
            ]);
        };

        $conn->onMessage = function (AsyncTcpConnection $conn, mixed $msg): void {
            if (is_array($msg)) {
                $this->onResponse($msg);
            } else {
                Log::channel('default')->warning('rpc malformed response', [
                    'type' => 'rpc_bad_msg', 'raw' => mb_substr((string) $msg, 0, 128),
                ]);
            }
        };

        // 连接失败与正常关闭都会走到 destroy() → onClose：统一在此重连
        $conn->onClose = function () use (&$reconnectDelay, $reconnectMax, $address): void {
            // 清理失效连接引用，并快速失败挂在其上的 pending（防悬挂）
            $this->failPendingOnConnectionDown();

            // 指数退避重连
            $delay = $reconnectDelay;
            $reconnectDelay = min($reconnectDelay * 2, $reconnectMax);
            Log::channel('default')->warning('rpc connection closed, reconnecting', [
                'type' => 'rpc_reconnect', 'address' => $address, 'delay' => $delay,
            ]);
            Timer::add($delay, fn () => $this->spawnConnection(), [], false);
        };

        $conn->onError = function (AsyncTcpConnection $conn, $code, $msg): void {
            Log::channel('default')->error('rpc connection error', [
                'type' => 'rpc_conn_err', 'code' => $code, 'error' => $msg,
            ]);
        };

        $conn->connect();
    }

    /** 连接断开：对挂在该连接上的 pending 快速失败，并清理失效连接 */
    private function failPendingOnConnectionDown(): void
    {
        // 先收集失效连接 id
        $dead = [];
        foreach ($this->connections as $idx => $c) {
            if ($c->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                $dead[$idx] = $c;
                unset($this->connections[$idx]);
            }
        }
        if ($dead === []) {
            return;
        }

        foreach ($this->pending as $id => $ctx) {
            if (in_array($ctx['conn'], $dead, true)) {
                unset($this->pending[$id]);
                Timer::del($ctx['timer']);
                $this->mErr++;
                $this->markFailure();
                $this->safeErr($ctx['onErr'], new RpcException(
                    'rpc service connection lost',
                    RpcException::UNAVAILABLE,
                    null,
                    $id,
                ));
            }
        }
    }

    // ------------------------------------------------------------------
    //  简易熔断（滑动计数 + 半开探测）
    // ------------------------------------------------------------------

    private function markSuccess(): void
    {
        if (!$this->breakerOpen) {
            return;
        }
        $this->successCount++;
        if ($this->successCount >= (int) ($this->config['breaker']['half_open_ok'] ?? 3)) {
            $this->breakerOpen = false;
            $this->failCount = 0;
            $this->successCount = 0;
            Log::channel('default')->info('breaker closed', ['type' => 'breaker', 'state' => 'closed']);
        }
    }

    private function markFailure(): void
    {
        if ($this->breakerOpen) {
            return;
        }
        $this->failCount++;
        $window = (int) ($this->config['breaker']['window'] ?? 20);
        $threshold = (float) ($this->config['breaker']['fail_rate'] ?? 0.5);
        $total = $this->failCount + $this->successCount;
        if ($total >= $window && $this->failCount / $total >= $threshold) {
            $this->breakerOpen = true;
            $this->breakerOpenedAt = microtime(true);
            $this->failCount = 0;
            $this->successCount = 0;
            Log::channel('default')->error('breaker opened', ['type' => 'breaker', 'state' => 'open']);
        }
    }

    /** 熔断开启且冷却期未过 → 快速失败；冷却期后放行探测请求（半开） */
    private function checkBreaker(): ?RpcException
    {
        if (!$this->breakerOpen) {
            return null;
        }
        $cooldown = (float) ($this->config['breaker']['cooldown'] ?? 5.0);
        if (microtime(true) - $this->breakerOpenedAt >= $cooldown) {
            return null;
        }
        return new RpcException('circuit breaker open (mysql rpc degraded)', RpcException::UNAVAILABLE);
    }

    // ------------------------------------------------------------------
    //  回滚模式：同步 PDO
    // ------------------------------------------------------------------

    private function createPdo(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('MYSQL_HOST') ?: '127.0.0.1',
            getenv('MYSQL_PORT') ?: '3306',
            getenv('MYSQL_DATABASE') ?: 'test',
        );
        return new PDO($dsn, getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function pdoQuery(string $method, string $sql, array $bindings): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($bindings));
        if ($method === 'Mysql.query') {
            return ['rows' => $stmt->fetchAll(), 'cost_ms' => 0];
        }
        return [
            'affected_rows' => $stmt->rowCount(),
            'last_insert_id' => (int) $this->pdo->lastInsertId() ?: null,
            'cost_ms' => 0,
        ];
    }

    // ------------------------------------------------------------------
    //  安全回调（异常隔离：回调抛错不能击穿事件循环）
    // ------------------------------------------------------------------

    private function safeOk(callable $cb, array $result, int $costMs): void
    {
        try {
            $cb($result, $costMs);
        } catch (Throwable $e) {
            Log::channel('default')->error('onOk callback error', [
                'type' => 'callback_err', 'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeErr(callable $cb, RpcException $e): void
    {
        try {
            $cb($e);
        } catch (Throwable $inner) {
            Log::channel('default')->error('onErr callback error', [
                'type' => 'callback_err', 'error' => $inner->getMessage(),
            ]);
        }
    }

    private function briefBindings(array $bindings): string
    {
        $out = [];
        foreach ($bindings as $v) {
            $out[] = is_scalar($v) || $v === null ? (string) json_encode($v) : gettype($v);
        }
        return mb_substr('[' . implode(',', $out) . ']', 0, 256);
    }

    /** 当前挂起请求数（监控/健康检查用） */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * 监控指标（计划 3.3）：请求量/失败率/超时率/P95。
     * 仅统计 RPC 模式（pdo 回滚模式不计入）。
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $lat = $this->mLatency;
        sort($lat);
        $pct = function (float $q) use ($lat): ?int {
            if ($lat === []) {
                return null;
            }
            $idx = (int) floor($q * count($lat));
            return $lat[max(0, min($idx, count($lat) - 1))];
        };
        return [
            'mode' => $this->pdo !== null ? 'pdo-fallback' : 'rpc',
            'uptime_s' => (int) (microtime(true) - $this->startedAt),
            'total' => $this->mTotal,
            'ok' => $this->mOk,
            'error' => $this->mErr,
            'timeout' => $this->mTimeout,
            'error_rate' => $this->mTotal > 0 ? round($this->mErr / $this->mTotal, 4) : 0.0,
            'timeout_rate' => $this->mTotal > 0 ? round($this->mTimeout / $this->mTotal, 4) : 0.0,
            'pending' => count($this->pending),
            'connections' => count($this->connections),
            'breaker_open' => $this->breakerOpen,
            'latency_ms' => [
                'p50' => $pct(0.50),
                'p95' => $pct(0.95),
                'p99' => $pct(0.99),
                'samples' => count($lat),
            ],
        ];
    }

    private function sampleLatency(int $costMs): void
    {
        $this->mLatency[] = $costMs;
        if (count($this->mLatency) > self::LATENCY_SAMPLES) {
            array_shift($this->mLatency);
        }
    }
}
