#!/bin/sh
set -e

APP=/var/www/html/erp

# Seed persistent volumes: add missing files, never overwrite saved settings/uploads
for d in config uploads logs assets/json; do
    mkdir -p "$APP/$d"
    cp -an "/opt/erp-seed/$d/." "$APP/$d/"
done

# Code files in config/ always come from the image so upgrades take effect
cp -af /opt/erp-seed/config/*.php "$APP/config/"

chown -R www-data:www-data "$APP/config" "$APP/uploads" "$APP/logs" "$APP/assets/json"

exec docker-php-entrypoint "$@"
