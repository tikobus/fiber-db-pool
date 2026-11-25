#!/usr/bin/env bash
#
# AmpMySQL RPC 服务运维脚本
#
# 用法：./control.sh {start|stop|restart|status|log|bench|test}
#
set -euo pipefail

cd "$(dirname "$0")/.."

PID_FILE=logs/server.pid
LOG_FILE=logs/rpc.log

# 数据库连接（可用环境变量覆盖）
export MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
export MYSQL_PORT="${MYSQL_PORT:-3306}"
export MYSQL_USER="${MYSQL_USER:-root}"
export MYSQL_PASSWORD="${MYSQL_PASSWORD:-123456}"
export MYSQL_DATABASE="${MYSQL_DATABASE:-test}"

start() {
    if [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        echo "already running (pid $(cat "$PID_FILE"))"
        return 0
    fi
    mkdir -p logs
    nohup php bin/server.php >> logs/stdout.log 2>&1 &
    echo $! > "$PID_FILE"
    sleep 0.5
    echo "started, pid $(cat "$PID_FILE")"
}

stop() {
    if [[ ! -f "$PID_FILE" ]]; then
        echo "not running"
        return 0
    fi
    pid=$(cat "$PID_FILE")
    if ! kill -0 "$pid" 2>/dev/null; then
        echo "stale pid file, removing"
        rm -f "$PID_FILE"
        return 0
    fi
    # SIGTERM 优雅停机（等待存量请求与连接池关闭）
    kill -TERM "$pid"
    for i in $(seq 1 50); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.1
    done
    kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
    rm -f "$PID_FILE"
    echo "stopped"
}

case "${1:-}" in
    start)   start ;;
    stop)    stop ;;
    restart) stop; start ;;
    status)
        if [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
            echo "running (pid $(cat "$PID_FILE"))"
        else
            echo "not running"
            exit 1
        fi
        ;;
    log)     tail -f "$LOG_FILE" ;;
    test)    php tests/client.php "${RPC_ADDR:-127.0.0.1:9501}" "${2:-all}" ;;
    bench)   php tests/bench.php "${RPC_ADDR:-127.0.0.1:9501}" "${2:-50}" "${3:-100}" ;;
    *)       echo "Usage: $0 {start|stop|restart|status|log|test|bench}"; exit 1 ;;
esac
