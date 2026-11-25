<?php

declare(strict_types=1);

namespace AmpMysqlRpc;

use Amp\ByteStream\WritableResourceStream;
use Amp\Log\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\Logger;

/**
 * 结构化日志：JSON-RPC 两侧统一字段 requestId / type / sql / bind / cost_ms / error / peer。
 * 使用 amphp/log 异步写入，避免阻塞事件循环。
 */
final class LoggerFactory
{
    public static function create(array $logConfig): Logger
    {
        $level = Level::fromName(ucfirst(strtolower((string) ($logConfig['level'] ?? 'info'))));
        $logger = new Logger('amp-mysql-rpc');

        // stderr 输出（容器友好）
        $stderr = new StreamHandler(\Amp\ByteStream\getStderr(), Level::Debug);
        $stderr->setFormatter(new LineFormatter(
            format: "[%datetime%] %channel%.%level_name% %message%\n",
            dateFormat: 'Y-m-d\TH:i:s.vP',
            allowInlineLineBreaks: false,
            ignoreEmptyContextAndExtra: true,
        ));
        $logger->pushHandler($stderr);

        // 文件输出（可选）
        $file = (string) ($logConfig['file'] ?? '');
        if ($file !== '') {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                $stream = @fopen($file, 'a');
                if ($stream !== false) {
                    $handler = new StreamHandler(
                        new WritableResourceStream($stream),
                        $level,
                    );
                    $handler->setFormatter(new LineFormatter(
                        format: "[%datetime%] %channel%.%level_name% %message%\n",
                        dateFormat: 'Y-m-d\TH:i:s.vP',
                        allowInlineLineBreaks: false,
                        ignoreEmptyContextAndExtra: true,
                    ));
                    $logger->pushHandler($handler);
                }
            }
        }

        return $logger;
    }
}
