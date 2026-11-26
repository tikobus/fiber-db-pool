FROM php:8.2-cli-alpine

# amphp 纯 PHP 实现，无额外扩展依赖；如需更佳性能可启用 ext-ev / ext-event
RUN docker-php-ext-install opcache && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /app

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    composer install --no-dev --no-scripts --optimize-autoloader --no-progress && \
    composer clear-cache

COPY . .

RUN mkdir -p logs && chmod 777 logs

EXPOSE 9501

# 全链路探活：Mysql.ping 真实执行 SELECT 1（MySQL 不可达则失败）
HEALTHCHECK --interval=5s --timeout=3s --retries=10 --start-period=5s \
    CMD php -r '$c=@stream_socket_client("tcp://127.0.0.1:9501",$e,$s,2);if(!$c){exit(1);}fwrite($c,"{\"jsonrpc\":\"2.0\",\"id\":\"hc\",\"method\":\"Mysql.ping\",\"params\":[]}\n");$r=fgets($c);exit(strpos($r,"\"pong\":true")!==false?0:1);'

CMD ["php", "bin/server.php"]
