# PHP + Composer layer for the app, queue worker and scheduler images.
# Build context is the repo root: docker build -f deploy/Dockerfile .

FROM php:8.4-fpm AS base

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql zip opcache pcntl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM node:24-alpine AS assets

WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/css ./resources/css
COPY resources/js ./resources/js
RUN npm run build

FROM base AS vendor

COPY composer.json composer.lock auth.json* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base

COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /src/public/build ./public/build
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php artisan config:cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x artisan

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
