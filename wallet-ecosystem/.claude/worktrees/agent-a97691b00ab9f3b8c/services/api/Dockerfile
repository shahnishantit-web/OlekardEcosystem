# ─── Stage 1: base ────────────────────────────────────────────────────────────
# PHP 8.4 with required extensions
FROM php:8.4-fpm-alpine AS base

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    curl \
    libpq-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        intl \
        mbstring \
        opcache \
        pcntl \
    && apk del --no-cache $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
ENV PHP_OPCACHE_ENABLE=1
ENV PHP_OPCACHE_MEMORY_CONSUMPTION=256
ENV PHP_OPCACHE_MAX_ACCELERATED_FILES=20000
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-interaction --no-scripts

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# ─── Stage 2: dev ─────────────────────────────────────────────────────────────
# Adds Xdebug for local development
FROM base AS dev

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del --no-cache $PHPIZE_DEPS

RUN composer install --no-interaction --no-scripts

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
ENV XDEBUG_MODE=coverage

# ─── Stage 3: prod ────────────────────────────────────────────────────────────
# Lean production image; no dev tools or Xdebug
FROM base AS prod

RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:8080/health || exit 1

USER www-data

CMD ["php-fpm", "-R"]
