<?php

declare(strict_types=1);

namespace AmpMysqlRpc\JsonRpc;

/**
 * JSON-RPC 2.0 协议编解码与错误码定义。
 * 传输格式：一行一个 JSON 对象，换行符 \n 分隔（与 workerman/jsonrpc 兼容）。
 */
final class Protocol
{
    // ---- 标准错误码 ----
    public const E_PARSE            = -32700; // 非法 JSON
    public const E_INVALID_REQUEST  = -32600; // 缺字段 / 字段非法
    public const E_METHOD_NOT_FOUND = -32601; // method 不在白名单
    public const E_INVALID_PARAMS   = -32602; // params 结构错误
    public const E_INTERNAL         = -32603; // 未预期异常

    // ---- 业务错误码 ----
    public const E_MYSQL            = -32000; // SQL 执行错误（语法/约束等）
    public const E_SQL_TIMEOUT      = -32001; // SQL 执行超时
    public const E_OVERLOAD         = -32002; // 过载：并发槽位排队超时
    public const E_MYSQL_CONNECT    = -32003; // MySQL 连接失败（重试后仍不可用）

    public const ERROR_MESSAGES = [
        self::E_PARSE => 'Parse error',
        self::E_INVALID_REQUEST => 'Invalid Request',
        self::E_METHOD_NOT_FOUND => 'Method not found',
        self::E_INVALID_PARAMS => 'Invalid params',
        self::E_INTERNAL => 'Internal error',
        self::E_MYSQL => 'SQL execution failed',
        self::E_SQL_TIMEOUT => 'SQL execution timeout',
        self::E_OVERLOAD => 'Service overloaded',
        self::E_MYSQL_CONNECT => 'MySQL connection failed',
    ];

    /** 单行报文最大长度（防内存攻击） */
    public const MAX_LINE_BYTES = 1024 * 1024;

    /**
     * 解析单行请求报文。返回 [request, error] 二元组，二者互斥。
     *
     * @return array{0: ?array, 1: ?array{code:int, message:string, data?:array}
     */
    public static function parseRequest(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [null, self::error(self::E_INVALID_REQUEST, 'empty line')];
        }

        try {
            $msg = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [null, self::error(self::E_PARSE, $e->getMessage())];
        }

        if (!is_array($msg)) {
            return [null, self::error(self::E_INVALID_REQUEST, 'request must be an object')];
        }

        if (($msg['jsonrpc'] ?? null) !== '2.0') {
            return [null, self::error(self::E_INVALID_REQUEST, 'jsonrpc must be "2.0"')];
        }

        if (!array_key_exists('id', $msg) || (!is_string($msg['id']) && !is_int($msg['id']))) {
            return [null, self::error(self::E_INVALID_REQUEST, 'id must be string or int')];
        }

        if (!isset($msg['method']) || !is_string($msg['method']) || $msg['method'] === '') {
            return [null, self::error(self::E_INVALID_REQUEST, 'method required')];
        }

        return [$msg, null];
    }

    /** @param mixed $id */
    public static function success(mixed $id, array $result): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @param mixed $id
     * @param array{code:int, message:string, data?:array} $err
     */
    public static function failure(mixed $id, array $err): string
    {
        $body = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $err['code'],
                'message' => $err['message'],
            ],
        ];
        if (!empty($err['data'])) {
            $body['error']['data'] = $err['data'];
        }

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @return array{code:int, message:string, data?:array}
     */
    public static function error(int $code, string $detail, array $data = []): array
    {
        $err = [
            'code' => $code,
            'message' => self::ERROR_MESSAGES[$code] ?? 'Server error',
        ];
        if ($detail !== '') {
            $data['detail'] = mb_substr($detail, 0, 512);
        }
        if ($data !== []) {
            $err['data'] = $data;
        }

        return $err;
    }
}
