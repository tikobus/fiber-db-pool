<?php

declare(strict_types=1);

namespace app\rpc;

/**
 * RPC 客户端错误：携带 HTTP 语义状态码，便于网关统一响应。
 */
class RpcException extends \RuntimeException
{
    /** 网关侧错误（配置错误/序列化失败等） */
    public const GATEWAY = 500;
    /** RPC 服务不可达 / 连接断开 */
    public const UNAVAILABLE = 503;
    /** RPC 总超时 */
    public const TIMEOUT = 504;
    /** RPC 服务返回业务错误（SQL 失败/过载等） */
    public const RPC_ERROR = 500;

    public function __construct(
        string $message,
        public readonly int $httpStatus = self::RPC_ERROR,
        public readonly ?int $rpcCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
