#!/bin/sh
set -eu
if [ "$1" = "php-fpm" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo 'Dependencies are missing. Run: docker compose run --rm php composer install' >&2
        exit 1
    fi
    if [ "${APP_ENV:-dev}" = prod ]; then
        printf '\nopcache.validate_timestamps=0\n' > /tmp/opcache-production.ini
        export PHP_INI_SCAN_DIR="/usr/local/etc/php/conf.d:/tmp"
        php bin/console cache:clear --env=prod --no-debug
    fi
fi
exec docker-php-entrypoint "$@"
