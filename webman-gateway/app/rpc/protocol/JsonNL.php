<?php

declare(strict_types=1);

namespace app\rpc\protocol;

use Workerman\Connection\ConnectionInterface;

/**
 * JsonNL 协议：一行一个 JSON 对象，换行符 \n 分隔。
 *
 * 与 AmpMySQL RPC 服务端（BufferedReader::readUntil("\n")）及
 * walkor/workerman-jsonrpc 的换行分包格式完全兼容。
 *
 * input()  返回当前完整包长度（含 \n），无完整包返回 0，包超长抛异常断连
 * decode() 将一行 JSON 解析为数组
 * encode() 数组编码为 JSON + \n
 */
class JsonNL
{
    /** 单包上限（与服务端 MAX_LINE_BYTES 一致：1 MiB） */
    public const MAX_PACKAGE_SIZE = 1024 * 1024;

    /**
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        $pos = strpos($buffer, "\n");
        if ($pos === false) {
            // 无完整包；防御超长包（无换行的内存攻击）
            if (strlen($buffer) >= self::MAX_PACKAGE_SIZE) {
                $connection->close();
                return 0;
            }
            return 0;
        }

        $length = $pos + 1;
        if ($length > self::MAX_PACKAGE_SIZE) {
            $connection->close();
            return 0;
        }

        return $length;
    }

    /**
     * @param string $buffer
     * @return array|null
     */
    public static function decode(string $buffer): ?array
    {
        $data = json_decode(rtrim($buffer, "\r\n"), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param mixed $data
     * @return string
     */
    public static function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
