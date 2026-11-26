# fiber-db-pool

**Swoole-free 异步数据库并发方案**：Workerman Fiber 协程 HTTP 网关 → 自研异步 JSON-RPC TCP 客户端 → AmpMySQL RPC 代理（amphp/mysql 连接池）→ MySQL 8.4

> 名字即架构：**Fiber**（纯 PHP 用户态协程，无 Swoole/Swow 依赖）· **DB**（数据库代理）· **Pool**（连接池）

```
┌─────────────┐   HTTP    ┌──────────────────┐   JSON-RPC/TCP (9501)   ┌───────────────┐        ┌─────────┐
│   Client    │ ────────> │  webman-gateway   │ ──────────────────────> │ amp-mysql-rpc │ ────── │ MySQL   │
│  (wrk/curl) │ <──────── │  Workerman Fiber  │ <────────────────────── │   amphp v3    │ <───── │  8.4    │
└─────────────┘           │  8787, 32 workers │   \n 分包, 1MiB 上限     │  池16/槽64    │        └─────────┘
                          └──────────────────┘                          └───────────────┘
```

**红线约束**（详见 docs/03-安全规范文档.md）：
- 不依赖 Swoole / Swow（Workerman Fiber 纯 PHP 用户态协程 + amphp revolt 循环）
- 前端只传「模板名 + 参数」，SQL 模板白名单硬编码于网关（`webman-gateway/app/rpc/SqlTemplates.php`）
- 所有 SQL 预编译绑定；写操作强制 `X-Idempotency-Key`；RPC 客户端禁止自动重试写操作
- 超时分级：RPC 总超时 3s（网关）> SQL 超时 2s（服务端）> 排队 0.5s；只读瞬态错误重试 1 次

---

## 目录结构

```
amp-mysql-rpc/          # 阶段 1：AmpPHP JSON-RPC MySQL 代理服务
  bin/server.php        #   入口（amphp TCP 服务，监听 9501）
  bin/control.sh        #   运维脚本 start/stop/restart/status/log/test/bench
  src/                  #   Config / LoggerFactory / MysqlManager / RpcServer / JsonRpc/*
  tests/                #   自测客户端 + 压测脚本
  Dockerfile
webman-gateway/         # 阶段 2：Webman HTTP 网关
  app/rpc/              #   MysqlRpcClient（连接池+超时+重连+熔断）/ SqlTemplates / JsonNL / RpcException
  app/controller/       #   UserController（get/list/create/health）/ StatsController
  app/bootstrap/        #   RpcPoolWarmup（worker 启动预热连接）
  config/               #   rpc.php / route.php / process.php / server.php(event_loop=Fiber)
  Dockerfile
docker/mysql/init.sql   #   演示表 rpc_test_user 结构 + 种子数据
docker-compose.yml      #   mysql + mysql-rpc + webman 三件套
docs/                   #   00-开发计划 / 01-接口协议 / 02-配置 / 03-安全规范
```

## 本地快速开始（不用 docker）

```bash
# 1. 准备 MySQL（本地 127.0.0.1:3306，root/123456，库 test）
mysql -uroot -p123456 test < docker/mysql/init.sql

# 2. 启动 RPC 服务（端口 9501）
cd amp-mysql-rpc && ./bin/control.sh start && ./bin/control.sh test

# 3. 启动网关（端口 8787）
cd ../webman-gateway && composer install && php start.php start -d

# 4. 验证
curl http://127.0.0.1:8787/api/health
curl "http://127.0.0.1:8787/api/user?id=1"
curl -X POST http://127.0.0.1:8787/api/user \
     -H "Content-Type: application/json" -H "X-Idempotency-Key: demo-001" \
     -d '{"name":"dave"}'
curl http://127.0.0.1:8787/api/stats
```

## docker-compose 部署

```bash
docker compose up -d --build
curl http://127.0.0.1:8787/api/health   # mysql:"up" 即全链路通
```

依赖链：mysql(healthy) → mysql-rpc(healthy, Mysql.ping 真探活) → webman。

## 接口一览

| 路由 | 说明 |
| --- | --- |
| `GET /api/user?id=1` | 单查（Fiber 同步风格） |
| `GET /api/users?after=0&limit=10` | 列表（回调 + wait() 桥接风格） |
| `POST /api/user` + `X-Idempotency-Key` | 写入（幂等） |
| `GET /api/health` | 全链路探活（503 = RPC/MySQL 异常） |
| `GET /api/stats` | 监控指标（请求量/失败率/超时率/P50/P95/P99/pending/连接数/熔断态） |

## 压测结论（阶段 4）

### 本机 macOS / 8C

| 场景 | 结果 |
| --- | --- |
| wrk 4t×50c `GET /api/user` | **3164 QPS**，延迟 p50 15ms（对标计划 800–1200，超预期） |
| wrk 4t×100c `GET /api/users` | 2223 QPS，p50 45ms |
| RPC 宕机注入 | 网关 503 快速失败 7.2 万 QPS，无挂起（熔断+重连生效） |
| SIGSTOP 冻结 RPC | 精确 3.003s 超时 504；5 并发同时超时（不串行堆积） |
| 并发隔离 | 20 并发 / 5 并发挂起互不阻塞，恢复自愈 |

### 云端 Google Cloud / 4C 16G / Intel Xeon @ 2.2GHz

| 场景 | 结果 |
| --- | --- |
| wrk 4t×100c 30s `GET /api/user?id=1` | **457.28 QPS**，延迟 p50 213ms / p75 227ms / p90 247ms / p99 298ms（avg 218ms，max 352ms）；13740 请求 / 30.05s，0 错误 |

```bash
wrk -t4 -c100 -d30s --latency http://127.0.0.1:8787/api/user?id=1
```

> 说明：云端为 2.2GHz 共享核心 Xeon，单核算力明显低于本机 Apple Silicon，QPS 差异主要来自硬件；延迟分布平稳（p99 298ms，无长尾堆积），吞吐仍高于同步 PDO 方案的对标区间（200–400）。

## 容错与回滚（阶段 5）

- **RPC 宕机**：快速失败 503 + 指数退避重连（0.2s→5s）+ 滑动窗口熔断（20 请求/50% 失败率/5s 冷却/半开探测）
- **回滚开关**：`RPC_ENABLE=0` 环境变量（或 `config/rpc.php` 的 `enable=false`）→ 网关降级为**同步 PDO 直连**，保留原有同步方案逃生通道，无需改代码
- **优雅停机**：RPC 侧 SIGTERM → 排空连接池；网关侧原生 `php start.php stop/reload`

```bash
# 一键回滚
cd webman-gateway && RPC_ENABLE=0 php start.php restart -d
```

## 运维命令速查

```bash
# RPC 服务
amp-mysql-rpc/bin/control.sh start|stop|restart|status|log|test|bench

# 网关
cd webman-gateway && php start.php start|stop|reload|status    # -d 后台 / 前台 DEBUG

# 日志
amp-mysql-rpc/logs/rpc.log          # RPC 侧（sql ok / 慢SQL / 连接事件）
webman-gateway/runtime/logs/        # 网关侧（rpc_call / rpc_connect / breaker / http）
```

## 二期方向（未实现）

服务发现与多实例负载均衡 / OpenTelemetry 链路追踪 / Redis 缓存层 / 批量 RPC 合并 / RPC Token 鉴权
