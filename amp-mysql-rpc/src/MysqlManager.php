<?php

declare(strict_types=1);

namespace AmpMysqlRpc;

use Amp\AsyncIterator;
use Amp\Future;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlResult;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlTransientResource;
use Amp\Sync\LocalSemaphore;
use Amp\Sync\Lock;
use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Monolog\Logger;
use Revolt\EventLoop;

use function Amp\async;

/**
 * MySQL 连接池统一封装（全局单例，由 Server 入口创建）。
 *
 * 能力：
 *  - amphp/mysql 连接池，限制最大连接数
 *  - query()  只读查询：瞬态连接错误自动重试（默认 1 次）
 *  - execute() 写操作：任何情况禁止重试（非幂等风险）
 *  - 单条 SQL 超时（Future 竞争实现，覆盖执行 + 取回全流程）
 *  - 并发槽位限流（LocalSemaphore），过载快速失败 -32002
 *  - 慢 SQL 日志（阈值可配）
 *
 * 已知限制（一期接受）：SQL 超时后底层 Fiber 无法强制取消，结果集仍会在后台
 * 完成并被静默丢弃，连接由连接池 idleTimeout / MySQL wait_timeout 回收。
 */
final class MysqlManager
{
    private MysqlConnectionPool $pool;
    private LocalSemaphore $slots;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
        $mysql = $config->mysql;
        $mysqlConfig = new MysqlConfig(
            host: (string) $mysql['host'],
            port: (int) $mysql['port'],
            user: (string) $mysql['user'],
            password: (string) $mysql['password'],
            database: (string) $mysql['database'],
        );

        $this->pool = new MysqlConnectionPool(
            $mysqlConfig,
            maxConnections: max(1, (int) $mysql['max_connections']),
            idleTimeout: max(1, (int) $mysql['idle_timeout']),
        );

        $this->slots = new LocalSemaphore(max(1, $config->maxConcurrency()));

        $this->logger->info('mysql pool initialized', [
            'type' => 'init',
            'mysql' => $mysqlConfig->getConnectionString(),
            'max_connections' => (int) $mysql['max_connections'],
            'max_concurrency' => $config->maxConcurrency(),
        ]);
    }

    /**
     * 只读查询（SELECT）。瞬态连接错误重试 read_retry 次。
     *
     * @param array<int|string, mixed> $bindings
     * @return array{rows: list<array<string, mixed>>, cost_ms: int}
     * @throws MysqlRpcException
     */
    public function query(string $sql, array $bindings, string $requestId): array
    {
        $t0 = microtime(true);
        $attempts = 0;
        $maxAttempts = 1 + max(0, $this->config->readRetry());
        $lastErr = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                [$rows] = $this->runGuarded($sql, $bindings, isRead: true);
                $this->logSql('query', $requestId, $sql, $bindings, $t0, $attempts, null, null);
                return ['rows' => $rows, 'cost_ms' => self::costMs($t0)];
            } catch (MysqlRpcException $e) {
                $willRetry = $e->retryable && $attempts < $maxAttempts;
                $this->logSql('query', $requestId, $sql, $bindings, $t0, $attempts, $e, $willRetry);
                if (!$willRetry) {
                    throw $this->finalizeReadError($e);
                }
                $lastErr = $e;
            }
        }

        throw $this->finalizeReadError($lastErr ?? new MysqlRpcException(ProtocolError::MYSQL, 'unknown', false));
    }

    /**
     * 写操作（INSERT/UPDATE/DELETE）。禁止重试。
     *
     * @param array<int|string, mixed> $bindings
     * @return array{affected_rows: int, last_insert_id: int|null, cost_ms: int}
     * @throws MysqlRpcException
     */
    public function execute(string $sql, array $bindings, string $requestId): array
    {
        $t0 = microtime(true);
        try {
            [$affected, $insertId] = $this->runGuarded($sql, $bindings, isRead: false);
            $this->logSql('execute', $requestId, $sql, $bindings, $t0, 1, null, null);
            return [
                'affected_rows' => $affected,
                'last_insert_id' => $insertId,
                'cost_ms' => self::costMs($t0),
            ];
        } catch (MysqlRpcException $e) {
            $this->logSql('execute', $requestId, $sql, $bindings, $t0, 1, $e, false);
            throw $e;
        }
    }

    /** 健康检查：SELECT 1（不走 RPC 协议，直连池） */
    public function ping(): bool
    {
        [$rows] = $this->runGuarded('SELECT 1 AS ok', [], isRead: true);
        return ($rows[0]['ok'] ?? null) === 1;
    }

    /**
     * 槽位限流 + SQL 超时 + 驱动调用。
     * 返回：isRead=true → [rows]；isRead=false → [affected_rows, last_insert_id]。
     *
     * @param array<int|string, mixed> $bindings
     * @return array{0: mixed, 1?: int|null}
     * @throws MysqlRpcException
     */
    private function runGuarded(string $sql, array $bindings, bool $isRead): array
    {
        // 1. 并发槽位（保护 MySQL），排队超时 → -32002 过载
        $lock = $this->acquireSlot();

        try {
            // 2. SQL 执行（含结果取回）整体置于超时竞争之下
            $future = async(fn (): array => $isRead
                ? [$this->fetchRows($sql, $bindings)]
                : $this->applyWrite($sql, $bindings));

            try {
                /** @var array */
                return $future->await(new TimeoutCancellation(
                    $this->config->sqlTimeout(),
                    'SQL execution timed out',
                ));
            } catch (TimeoutException|CancelledException $e) {
                // CancelledException::getPrevious() 为 TimeoutException 时同样视为超时
                if (!$e instanceof TimeoutException && !$e->getPrevious() instanceof TimeoutException) {
                    $transient = $this->isTransient($e);
                    throw new MysqlRpcException(
                        ProtocolError::MYSQL,
                        self::brief($e),
                        retryable: $transient,
                        transient: $transient,
                    );
                }

                // 底层无法取消：继续观察避免 unhandled，连接由池回收
                $future->catch(function (\Throwable $t): void {
                    $this->logger->warning('timed-out sql eventually failed', [
                        'type' => 'timeout_bg', 'error' => self::brief($t),
                    ]);
                });
                throw new MysqlRpcException(
                    ProtocolError::SQL_TIMEOUT,
                    'sql execution exceeded ' . $this->config->sqlTimeout() . 's',
                    retryable: false,
                );
            } catch (\Throwable $e) {
                $transient = $this->isTransient($e);
                throw new MysqlRpcException(
                    ProtocolError::MYSQL,
                    self::brief($e),
                    retryable: $transient,
                    transient: $transient,
                );
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * 获取并发槽位，排队超时快速失败（-32002）。
     * 排队超时后若稍后获得锁，立即释放防泄漏。
     */
    private function acquireSlot(): Lock
    {
        $future = async(fn (): Lock => $this->slots->acquire());
        try {
            return $future->await(new TimeoutCancellation(
                $this->config->queueTimeout(),
                'concurrency slot queue timeout',
            ));
        } catch (TimeoutException|CancelledException $e) {
            $future
                ->then(fn (Lock $late): mixed => $late->release())
                ->catch(fn (): mixed => null);
            throw new MysqlRpcException(
                ProtocolError::OVERLOAD,
                'concurrency limit reached, queue timeout',
                retryable: true, // 过载可由客户端稍后重试（含只读写）
            );
        }
    }

    /**
     * 执行并取回所有行（协程体内，受超时竞争保护）。
     *
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $sql, array $bindings): array
    {
        /** @var MysqlResult $result */
        $result = $this->pool->execute($sql, $bindings);
        $rows = [];
        while (null !== ($row = $result->fetchRow())) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * 执行写操作并提取 affected / insert_id（协程体内，受超时竞争保护）。
     *
     * @param array<int|string, mixed> $bindings
     * @return array{0: int, 1: int|null}
     */
    private function applyWrite(string $sql, array $bindings): array
    {
        /** @var MysqlResult $result */
        $result = $this->pool->execute($sql, $bindings);
        return [
            $result->getRowCount() ?? 0,
            $result->getLastInsertId(),
        ];
    }

    /**
     * 只读查询重试用尽后：连接类错误收敛为 -32003（服务不可用），其余保持原错误码。
     */
    private function finalizeReadError(MysqlRpcException $e): MysqlRpcException
    {
        if ($e->transient || $e->rpcCode === ProtocolError::MYSQL_CONNECT) {
            return new MysqlRpcException(
                ProtocolError::MYSQL_CONNECT,
                'mysql unreachable after retries: ' . $e->getMessage(),
                retryable: false,
            );
        }
        return $e;
    }

    private function isTransient(\Throwable $e): bool
    {
        return $e instanceof SqlTransientResource
            || $e instanceof SqlConnectionException
            || str_contains($e->getMessage(), 'connection')
            || str_contains($e->getMessage(), 'Connection');
    }

    private static function brief(\Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 512);
    }

    private function logSql(
        string $type,
        string $requestId,
        string $sql,
        array $bindings,
        float $t0,
        int $attempts,
        ?MysqlRpcException $err,
        ?bool $willRetry,
    ): void {
        $costMs = self::costMs($t0);
        $fields = [
            'type' => $type,
            'request_id' => $requestId,
            'sql' => mb_substr($sql, 0, 512),
            'bind' => $this->stringifyBindings($bindings),
            'cost_ms' => $costMs,
            'attempts' => $attempts,
            'error' => $err?->getMessage(),
            'retry' => $willRetry,
        ];

        if ($err === null && $costMs >= $this->config->slowSqlMs()) {
            $this->logger->warning('slow sql', $fields);
        } elseif ($err !== null) {
            $this->logger->error('sql fail', $fields);
        } else {
            $this->logger->info('sql ok', $fields);
        }
    }

    private function stringifyBindings(array $bindings): string
    {
        $out = [];
        foreach ($bindings as $v) {
            $out[] = (is_scalar($v) || $v === null) ? (string) json_encode($v) : gettype($v);
        }
        return mb_substr('[' . implode(',', $out) . ']', 0, 256);
    }

    private static function costMs(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    /** 优雅关闭连接池 */
    public function close(): void
    {
        try {
            $this->pool->close();
        } catch (\Throwable $e) {
            $this->logger->warning('pool close error', [
                'type' => 'shutdown',
                'error' => self::brief($e),
            ]);
        }
    }
}

/**
 * RPC 业务异常：携带协议错误码与重试语义。
 */
final class MysqlRpcException extends \RuntimeException
{
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly bool $retryable,
        public readonly bool $transient = false,
    ) {
        parent::__construct($message);
    }
}

/** 协议错误码别名 */
final class ProtocolError
{
    public const MYSQL = \AmpMysqlRpc\JsonRpc\Protocol::E_MYSQL;
    public const SQL_TIMEOUT = \AmpMysqlRpc\JsonRpc\Protocol::E_SQL_TIMEOUT;
    public const OVERLOAD = \AmpMysqlRpc\JsonRpc\Protocol::E_OVERLOAD;
    public const MYSQL_CONNECT = \AmpMysqlRpc\JsonRpc\Protocol::E_MYSQL_CONNECT;
}
