# 统一基础镜像：ghcr.io/tikobus/php:8.5.13-rocky（与 amp-mysql-rpc 一致）
# 镜像内置：PHP 8.5.13（静态编译 pcntl/posix/mbstring/zip/curl/openssl/pdo_mysql 等扩展）
#           Composer 2.11.3（/usr/local/bin/composer）、php.ini 位于 /usr/local/php/8.5/lib/
FROM ghcr.io/tikobus/php:8.5.13-rocky

# workerman 依赖的 pcntl/posix 已静态编译，无需 docker-php-ext-install；仅需开启 opcache
RUN echo "opcache.enable=1" >> /usr/local/php/8.5/lib/php.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/php/8.5/lib/php.ini

WORKDIR /app

# 先装依赖（利用镜像层缓存；composer 已内置，zip/curl 扩展可用于下载解压）
COPY composer.json composer.lock ./
RUN COMPOSER_MEMORY_LIMIT=2G composer install --no-dev --no-scripts --optimize-autoloader --no-progress && \
    composer clear-cache

COPY . .

EXPOSE 8787

# 全链路探活：经网关 → RPC → MySQL
HEALTHCHECK --interval=5s --timeout=3s --retries=6 --start-period=10s \
    CMD php -r '$b=@file_get_contents("http://127.0.0.1:8787/api/health",false,stream_context_create(["http"=>["timeout"=>2]]));exit($b!==false&&strpos($b,"\"mysql\":\"up\"")!==false?0:1);'

# 前台运行（DEBUG 模式，日志输出到 stdout/stderr）
CMD ["php", "start.php", "start"]
