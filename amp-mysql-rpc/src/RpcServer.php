<?php

declare(strict_types=1);

namespace AmpMysqlRpc;

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\StreamException;
use Amp\Socket\ResourceSocket;
use Amp\Socket\ServerSocket;
use AmpMysqlRpc\JsonRpc\Dispatcher;
use AmpMysqlRpc\JsonRpc\Protocol;
use Monolog\Logger;
use Revolt\EventLoop;

use function Amp\Socket\listen;

/**
 * TCP JSON-RPC 服务器主体。
 *
 *  - 监听 listen 配置（默认 tcp://0.0.0.0:9501）
 *  - 每个连接由独立协程处理，逐行读取（\n 分包）
 *  - 每个请求再派发独立协程执行（同连接请求并发，响应乱序回写）
 *  - SIGINT / SIGTERM 优雅停机：停止接新连接 → 处理存量 → 关闭连接池
 */
final class RpcServer
{
    private \Amp\Socket\ResourceServerSocket $server;
    private bool $shuttingDown = false;
    /** @var resource[] 监听 socket 引用（用于关闭） */
    private $shutdownCallbacks = [];

    public function __construct(
        private readonly Config $config,
        private readonly MysqlManager $mysql,
        private readonly Logger $logger,
    ) {
    }

    public function run(): void
    {
        $this->server = listen($this->config->listen);
        $this->logger->info('rpc server listening', [
            'type' => 'server', 'listen' => $this->config->listen,
        ]);
        echo "AmpMySQL RPC server listening on {$this->config->listen}\n";

        // 信号处理：优雅停机
        $stop = function (string $sig): void {
            if ($this->shuttingDown) {
                return; // 再次收到信号则忽略，等待退出流程完成
            }
            $this->shuttingDown = true;
            $this->logger->info('shutdown signal received', ['type' => 'shutdown', 'signal' => $sig]);
            echo "\n[shutdown] received SIG{$sig}, draining...\n";

            // 停止接受新连接
            $this->server->close();

            // 存量连接由各自协程在 read 返回 null 后自然结束；
            // 给一个短缓冲期后关闭连接池并退出
            EventLoop::delay(0.5, function (): void {
                $this->logger->info('closing mysql pool', ['type' => 'shutdown']);
                $this->mysql->close();
                echo "[shutdown] mysql pool closed\n";
                exit(0);
            });
        };

        EventLoop::onSignal(\SIGINT, $stop);
        EventLoop::onSignal(\SIGTERM, $stop);

        // accept 循环
        while (true) {
            try {
                $socket = $this->server->accept();
            } catch (\Throwable) {
                if ($this->shuttingDown) {
                    break;
                }
                // 监听异常（如被外部关闭）时稍等后重试
                EventLoop::delay(0.1, fn () => null);
                continue;
            }

            if ($socket === null) {
                break;
            }

            $peer = $socket->getRemoteAddress()->toString();
            $this->logger->info('client connected', ['type' => 'conn', 'peer' => $peer]);

            EventLoop::queue(fn () => $this->handleClient($socket, $peer));
        }

        // 兜底：accept 循环退出（停机中），等待 delay 回调完成退出
    }

    private function handleClient(ResourceSocket $socket, string $peer): void
    {
        $dispatcher = new Dispatcher($this->mysql, $this->logger);
        $reader = new BufferedReader($socket);

        try {
            while (null !== ($line = $reader->readUntil("\n", limit: Protocol::MAX_LINE_BYTES))) {
                if ($this->shuttingDown) {
                    break;
                }

                $rawLine = $line;

                // 每个请求独立协程：同连接并发、响应乱序回写（JSON-RPC 按 id 匹配）
                EventLoop::queue(function () use ($socket, $rawLine, $peer, $dispatcher): void {
                    try {
                        [$request, $err] = Protocol::parseRequest($rawLine);
                        if ($err !== null) {
                            $response = Protocol::failure(null, $err);
                            $this->logger->info('bad request', [
                                'type' => 'rpc', 'peer' => $peer,
                                'error' => $err['message'],
                            ]);
                        } else {
                            $response = $dispatcher->dispatch($request, $peer);
                        }
                        $socket->write($response);
                    } catch (\Throwable $e) {
                        $this->logger->error('request handling crashed', [
                            'type' => 'rpc', 'peer' => $peer, 'error' => $e->getMessage(),
                        ]);
                        // 连接已关闭则忽略写回失败
                        try {
                            $socket->write(Protocol::failure(
                                null,
                                Protocol::error(Protocol::E_INTERNAL, 'handler crashed'),
                            ));
                        } catch (\Throwable) {
                            // ignore
                        }
                    }
                });
            }
        } catch (StreamException $e) {
            // 客户端断开或读异常：正常场景
            $this->logger->info('client read end', [
                'type' => 'conn', 'peer' => $peer, 'reason' => mb_substr($e->getMessage(), 0, 128),
            ]);
        } catch (\Throwable $e) {
            // 报文超限（BufferException）等
            $this->logger->warning('client error', [
                'type' => 'conn', 'peer' => $peer, 'error' => mb_substr($e->getMessage(), 0, 256),
            ]);
        } finally {
            $this->logger->info('client disconnected', ['type' => 'conn', 'peer' => $peer]);
            try {
                $socket->close();
            } catch (\Throwable) {
                // ignore
            }
        }
    }
}
