# Development/backend test image. Frontend assets and production delivery remain M1 gates.
FROM composer:2 AS composer
# Track stable PHP 8.5 patch releases on the existing Debian base.
FROM php:8.5-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libpq-dev libonig-dev libxml2-dev libsqlite3-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring dom pcntl \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
