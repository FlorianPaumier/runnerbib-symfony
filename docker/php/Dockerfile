# syntax=docker/dockerfile:1
FROM php:8.4-fpm-alpine AS base
WORKDIR /app
RUN apk add --no-cache icu-libs libpq libzip fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev \
    && docker-php-ext-install -j$(nproc) intl pdo_pgsql opcache zip \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/healthcheck.sh /usr/local/bin/php-healthcheck
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
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

FROM base AS production
ENV APP_ENV=prod APP_DEBUG=0
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var
USER www-data

FROM nginx:1.28-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/ /app/public/
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 CMD wget -q -O /dev/null http://127.0.0.1/health || exit 1
