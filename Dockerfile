```dockerfile
FROM php:8.2-fpm-alpine

# 安裝依賴
RUN apk add --no-cache \
    nginx \
    supervisor \
    && apk add --no-cache --virtual .build-deps \
    autoconf \
    gcc \
    g++ \
    make \
    && docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設置工作目錄
WORKDIR /var/www/html

# 複製專案文件
COPY . /var/www/html

# 設置權限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage

# 暴露端口
EXPOSE 9000

# 啟動 Supervisor 管理 PHP-FPM
CMD ["supervisord", "-c", "/var/www/html/supervisord.conf"]
```