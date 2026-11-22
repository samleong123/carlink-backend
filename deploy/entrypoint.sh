#!/bin/sh
set -eu
# APP_KEY must be provisioned once by the operator; never regenerate keys on restart.
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown www-data:www-data storage storage/app storage/app/private storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [ "${1:-}" = "php-fpm" ]; then
    exec php-fpm
fi
exec su -s /bin/sh www-data -c 'exec "$@"' -- sh "$@"
