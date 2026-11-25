<?php

declare(strict_types=1);

namespace AmpMysqlRpc\JsonRpc;

use AmpMysqlRpc\MysqlManager;
use AmpMysqlRpc\MysqlRpcException;
use Monolog\Logger;
use function Amp\async;

/**
 * JSON-RPC method 分发器：白名单 + 参数校验 + 调用 MysqlManager。
 *
 * 返回值恒为「已编码的响应行（含 \n）」，由 Server 写回客户端。
 */
final class Dispatcher
{
    public function __construct(
        private readonly MysqlManager $mysql,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{jsonrpc:string, id:string|int, method:string, params?:mixed} $request
     * @param string $peer 客户端地址
     * @return string 响应报文（单行，含换行符）
     */
    public function dispatch(array $request, string $peer): string
    {
        $id = $request['id'];
        $method = $request['method'];

        switch ($method) {
            case 'Mysql.query':
            case 'Mysql.execute':
                return $this->handleSql($request, $peer, $method);

            case 'Mysql.ping':
                try {
                    $ok = $this->mysql->ping();
                    return Protocol::success($id, ['pong' => $ok, 'mysql' => 'up']);
                } catch (\Throwable $e) {
                    $this->logger->error('ping fail', [
                        'type' => 'ping', 'peer' => $peer, 'error' => $e->getMessage(),
                    ]);
                    return Protocol::failure($id, Protocol::error(
                        Protocol::E_MYSQL_CONNECT,
                        $e->getMessage(),
                        ['peer' => $peer],
                    ));
                }

            default:
                $this->logger->info('method not found', [
                    'type' => 'rpc', 'method' => $method, 'peer' => $peer,
                ]);
                return Protocol::failure($id, Protocol::error(
                    Protocol::E_METHOD_NOT_FOUND,
                    "unknown method: {$method}",
                    ['peer' => $peer],
                ));
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleSql(array $request, string $peer, string $method): string
    {
        $id = $request['id'];
        $method = $request['method'];
        $params = $request['params'] ?? null;

        // ---- 参数校验（params 必须含 sql，可选 bindings）----
        if (!is_array($params) || !isset($params['sql'])) {
            return Protocol::failure($id, Protocol::error(
                Protocol::E_INVALID_PARAMS,
                'params.sql required',
                ['peer' => $peer],
            ));
        }

        $sql = $params['sql'] ?? null;
        if (!is_string($sql) || $sql === '' || strlen($sql) > 64 * 1024) {
            return Protocol::failure($id, Protocol::error(
                Protocol::E_INVALID_PARAMS,
                'params.sql must be non-empty string (<= 64KiB)',
                ['peer' => $peer],
            ));
        }

        $bindings = $params['bindings'] ?? [];
        if (!is_array($bindings)) {
            return Protocol::failure($id, Protocol::error(
                Protocol::E_INVALID_PARAMS,
                'params.bindings must be an array',
                ['peer' => $peer],
            ));
        }
        foreach ($bindings as $v) {
            if ($v !== null && !is_scalar($v)) {
                return Protocol::failure($id, Protocol::error(
                    Protocol::E_INVALID_PARAMS,
                    'params.bindings only accepts scalar / null values',
                    ['peer' => $peer],
                ));
            }
        }

        $requestId = is_string($id) ? $id : (string) $id;
        $isRead = ($method === 'Mysql.query');

        // 只读/写语义校验：Mysql.query 只允许 SELECT（防御式，非强制）
        if ($isRead && !$this->looksLikeSelect($sql)) {
            return Protocol::failure($id, Protocol::error(
                Protocol::E_INVALID_PARAMS,
                'Mysql.query only accepts SELECT statements, use Mysql.execute instead',
                ['peer' => $peer],
            ));
        }

        try {
            $result = $isRead
                ? $this->mysql->query($sql, $bindings, $requestId)
                : $this->mysql->execute($sql, $bindings, $requestId);
            return Protocol::success($id, $result);
        } catch (MysqlRpcException $e) {
            return Protocol::failure($id, Protocol::error(
                $e->rpcCode,
                $e->getMessage(),
                ['request_id' => $requestId, 'peer' => $peer],
            ));
        } catch (\Throwable $e) {
            $this->logger->error('unexpected error', [
                'type' => 'rpc', 'request_id' => $requestId,
                'method' => $method, 'error' => $e->getMessage(),
                'peer' => $peer,
            ]);
            return Protocol::failure($id, Protocol::error(
                Protocol::E_INTERNAL,
                $e->getMessage(),
                ['request_id' => $requestId, 'peer' => $peer],
            ));
        }
    }

    private function looksLikeSelect(string $sql): bool
    {
        return (bool) preg_match('/^\s*(SELECT|SHOW|DESC|DESCRIBE|EXPLAIN|WITH)\b/i', $sql);
    }
}
