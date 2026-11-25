# webman-gateway — HTTP 网关

本目录是 fiber-db-pool 项目的 **Webman HTTP 网关**（阶段 2 交付物）：
Workerman Fiber 事件循环 + 自研异步 JSON-RPC TCP 客户端（`app/rpc/`）。

## 结构

| 路径 | 说明 |
| ---- | ---- |
| `app/rpc/MysqlRpcClient.php` | RPC 客户端核心：连接池 / 3s 超时 / 重连退避 / 熔断 / Fiber 桥 |
| `app/rpc/SqlTemplates.php` | SQL 模板白名单（安全红线：前端只传模板名+参数） |
| `app/rpc/protocol/JsonNL.php` | `\n` 换行分包协议（1MiB 上限，与服务端一致） |
| `app/controller/` | UserController（get/list/create/health）、StatsController（/api/stats） |
| `app/bootstrap/RpcPoolWarmup.php` | worker 启动预热 RPC 连接池 |
| `config/rpc.php` | RPC 客户端配置（地址/超时/池/熔断/回滚开关） |

## 常用命令

```bash
composer install
php start.php start        # 前台 DEBUG
php start.php start -d     # 后台守护
php start.php reload|stop|status
```

## 环境变量

见 `.env.example`（`RPC_ADDRESS` / `RPC_ENABLE` / `MYSQL_*`）。

完整文档（协议 / 配置 / 安全 / 快速开始 / 压测结论）见项目根目录 `README.md` 与 `docs/`。
