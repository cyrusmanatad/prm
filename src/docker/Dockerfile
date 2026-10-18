# Development/test image with the complete Inertia client bundle.
FROM node:24-bookworm-slim@sha256:a5f4366e834695d5f363b920c104953a535d3a9864d1bdf74566a0ebe01daee5 AS frontend
WORKDIR /build
COPY package.json package-lock.json vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm ci --ignore-scripts --no-audit --no-fund && npm run build

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.5-cli-bookworm@sha256:819080ed413631f6ec564184dd9acd08d9425bd863b9f8b5876b5ea2147d55e7
# PHP 8.5 already includes DOM (with Lexbor), mbstring, and SQLite.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libpq-dev \
    && docker-php-ext-install -j2 pdo_pgsql pcntl \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress
COPY . .
COPY --from=frontend /build/public/build ./public/build
# Runtime configuration is injected by Compose, never copied from the host.
# An empty file also keeps Artisan's test runner from reporting Dotenv read warnings.
RUN touch .env && composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
# Configuration comes from the container environment; reload mode strips it
# from the HTTP child process when an .env file exists.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
