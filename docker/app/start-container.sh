#!/bin/sh
set -eu

MODE="${1:-app}"

cd /var/www/html

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

rm -f bootstrap/cache/*.php
php artisan optimize:clear >/dev/null 2>&1 || true
php artisan package:discover --ansi

case "$MODE" in
  app)
    php artisan storage:link --ansi || true
    php artisan migrate --force --ansi
    php artisan db:seed --class=AdminUserSeeder --force --ansi
    exec php-fpm
    ;;
  worker)
    exec php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=180 --max-time=3600 --ansi
    ;;
  scheduler)
    while true; do
      php artisan schedule:run --verbose --no-interaction
      sleep 60
    done
    ;;
  *)
    echo "Unknown container mode: $MODE" >&2
    exit 1
    ;;
esac
