# 统一基础镜像：ghcr.io/tikobus/php:8.5.13-rocky（与 webman-gateway 一致）
# 镜像内置：PHP 8.5.13（静态编译 pcntl/posix/mbstring/zip/curl/openssl/pdo_mysql 等扩展）
#           Composer 2.11.3（/usr/local/bin/composer）、php.ini 位于 /usr/local/php/8.5/lib/
FROM ghcr.io/tikobus/php:8.5.13-rocky

# amphp 纯 PHP 实现无需额外扩展；仅需开启 opcache（CLI 常驻进程）
RUN echo "opcache.enable=1" >> /usr/local/php/8.5/lib/php.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/php/8.5/lib/php.ini

WORKDIR /app

# 先装依赖（利用镜像层缓存；composer 已内置，zip/curl 扩展可用于下载解压）
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-progress && \
    composer clear-cache

COPY . .

RUN mkdir -p logs && chmod 777 logs

EXPOSE 9501

# 全链路探活：Mysql.ping 真实执行 SELECT 1（MySQL 不可达则失败）
HEALTHCHECK --interval=5s --timeout=3s --retries=10 --start-period=5s \
    CMD php -r '$c=@stream_socket_client("tcp://127.0.0.1:9501",$e,$s,2);if(!$c){exit(1);}fwrite($c,"{\"jsonrpc\":\"2.0\",\"id\":\"hc\",\"method\":\"Mysql.ping\",\"params\":[]}\n");$r=fgets($c);exit(strpos($r,"\"pong\":true")!==false?0:1);'

CMD ["php", "bin/server.php"]
