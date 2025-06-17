# Dockerfile (多階段建置範例)

# --- Stage 1: Builder ---
FROM php:8.2-fpm-alpine AS builder

# Install system dependencies
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    libzip-dev \
    libpq-dev \
    redis-dev \
    mysql-client \
    autoconf \
    g++ \
    make

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql opcache bcmath
RUN docker-php-ext-configure zip --with-libzip
RUN docker-php-ext-install zip
RUN pecl install -o -f redis \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

WORKDIR /app

# Copy application code for dependency installation
COPY composer.json composer.lock ./

# Install Composer dependencies (dev dependencies are needed for testing phase in CI)
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

# Optimize Laravel for production (optional, can be done at runtime)
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# --- Stage 2: Production ---
FROM php:8.2-fpm-alpine

# Install production system dependencies (minimal)
RUN apk add --no-cache \
    libzip \
    libpq \
    redis \
    mysql-client-libs \
    nginx # If you want to include Nginx in the same container (not recommended for large scale)

# Install PHP extensions for production (only what's needed)
RUN docker-php-ext-install pdo_mysql opcache bcmath
RUN docker-php-ext-configure zip --with-libzip
RUN docker-php-ext-install zip
RUN pecl install -o -f redis \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis

WORKDIR /var/www/html

# Copy only necessary files from builder stage
COPY --from=builder /app /var/www/html

# Set appropriate permissions
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Expose port (if Nginx is in this container)
EXPOSE 9000 # PHP-FPM default port

# Start PHP-FPM
CMD ["php-fpm"]