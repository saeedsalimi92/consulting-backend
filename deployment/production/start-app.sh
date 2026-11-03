#!/bin/sh
set -eu
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
