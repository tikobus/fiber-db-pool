FROM php:8.4-cli

# workerman 需要 pcntl/posix；opcache 提升加载性能
RUN docker-php-ext-install pcntl posix opcache && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# 先装依赖（利用镜像层缓存）
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-progress && \
    composer clear-cache

COPY . .

EXPOSE 8787

# 全链路探活：经网关 → RPC → MySQL
HEALTHCHECK --interval=5s --timeout=3s --retries=6 --start-period=10s \
    CMD php -r '$b=@file_get_contents("http://127.0.0.1:8787/api/health",false,stream_context_create(["http"=>["timeout"=>2]]));exit($b!==false&&strpos($b,"\"mysql\":\"up\"")!==false?0:1);'

# 前台运行（DEBUG 模式，日志输出到 stdout/stderr）
CMD ["php", "start.php", "start"]
