# Stage 1: Composer dependencies (vendor with an optimized autoloader)
FROM composer:2 AS composer_stage

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative

# Stage 2: Node build (Vite assets)
FROM node:22-alpine AS node_stage

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources

RUN npm run build

# Stage 3: Single runtime container: nginx + php-fpm + supervisor on port 80.
FROM php:8.4-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    libzip-dev \
    postgresql-dev \
    bash \
    shadow

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) pdo_pgsql opcache pcntl zip

# Install Composer (used for occasional one-off maintenance in the container)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Nginx to run as www-data (align with PHP-FPM)
RUN sed -i 's/user nginx;/user www-data;/g' /etc/nginx/nginx.conf 2>/dev/null || \
    echo "user www-data;" > /etc/nginx/conf.d/user.conf

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Configurations
COPY docker/php/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini
COPY docker/php/uploads.ini $PHP_INI_DIR/conf.d/uploads.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

# Working directory
WORKDIR /var/www/html

# Application code (the build context excludes .env, vendor, node_modules, public/build)
COPY --chown=www-data:www-data . .

# Vendor and built assets from the build stages
COPY --from=composer_stage --chown=www-data:www-data /app/vendor ./vendor
COPY --from=node_stage --chown=www-data:www-data /app/public/build ./public/build

# Runtime directories
RUN mkdir -p storage/framework/{sessions,views,cache} \
    storage/logs \
    bootstrap/cache \
    /var/log/supervisor \
    /var/log/supervisord \
    && chown -R www-data:www-data storage bootstrap/cache /var/lib/nginx /var/run /var/log/php-fpm /var/log/supervisord /var/log/supervisor \
    && chmod -R 775 storage bootstrap/cache /var/lib/nginx /var/log/php-fpm /var/log/supervisord /var/log/supervisor

EXPOSE 80

CMD ["/start.sh"]
