# syntax=docker/dockerfile:1
FROM php:8.4-fpm-alpine AS base
WORKDIR /app
RUN apk add --no-cache icu-libs libpq libzip fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev \
    && docker-php-ext-install -j$(nproc) intl pdo_pgsql opcache zip pcntl \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/healthcheck.sh /usr/local/bin/php-healthcheck
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=755 docker/php/scheduler.sh /app/docker/php/scheduler.sh
RUN chmod +x /usr/local/bin/php-healthcheck /usr/local/bin/app-entrypoint \
    && printf '\n[www]\nping.path = /ping\n' > /usr/local/etc/php-fpm.d/zz-health.conf
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 CMD php-healthcheck

FROM base AS development
ENV APP_ENV=dev APP_DEBUG=1
RUN apk add --no-cache git unzip \
    && apk add --no-cache --virtual .xdebug-build $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-build
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

FROM oven/bun:1.3.1-alpine AS assets
WORKDIR /app
COPY package.json bun.lock ./
RUN bun install --frozen-lockfile
COPY frontend/ ./frontend/
COPY templates/ ./templates/
RUN mkdir -p assets/styles && bun run --bun assets:build

FROM base AS production
ENV APP_ENV=prod APP_DEBUG=0
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
COPY --from=assets /app/assets/styles/app.css /app/assets/styles/app.css
# The image carries no environment file: the runtime provides every secret.
RUN printf 'APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=\n' > .env \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php bin/console importmap:install --env=prod --no-debug \
    && php bin/console asset-map:compile --env=prod --no-debug \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var
USER www-data

FROM nginx:1.28-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/ /app/public/
COPY --from=production /app/public/assets/ /app/public/assets/
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 CMD wget -q -O /dev/null http://127.0.0.1/health || exit 1
