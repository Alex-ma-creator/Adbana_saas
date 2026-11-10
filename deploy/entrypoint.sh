#!/bin/sh
set -eu
: "${APP_KEY:?APP_KEY is required}"
: "${APP_URL:?APP_URL is required}"
if [ "${APP_ENV:-}" = production ]; then
  case "$APP_URL" in https://*) ;; *) echo 'Production requires an HTTPS APP_URL' >&2; exit 1;; esac
fi
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php artisan package:discover --no-interaction >/dev/null
php artisan config:cache --no-interaction >/dev/null
exec "$@"
