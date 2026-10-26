#!/bin/sh
set -eu

cd /var/www/html

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ "${1:-api}" = "api" ]; then
    exec /usr/bin/supervisord -c /etc/supervisord.conf
fi

exec "$@"
