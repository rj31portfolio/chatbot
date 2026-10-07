#!/bin/sh
set -eu

cd /var/www

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is missing. Set a permanent Laravel key in Render Environment before deploying." >&2
    exit 1
fi

if [ -z "${DB_CONNECTION:-}" ]; then
    if [ -n "${DATABASE_URL:-${DB_URL:-}}" ]; then
        export DB_CONNECTION=pgsql
    else
        echo "Database configuration is missing. Set DB_CONNECTION and database credentials in Render Environment." >&2
        exit 1
    fi
fi

if [ "${DB_CONNECTION}" = "sqlite" ]; then
    echo "Configure an external MySQL or PostgreSQL database for Render. Container-local SQLite data does not survive redeploys." >&2
    exit 1
fi

export PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*) echo "PORT must be a number." >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be between 1 and 65535." >&2
    exit 1
fi

export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"
if [ -z "$APP_URL" ]; then
    echo "Set APP_URL to the public HTTPS URL of this service." >&2
    exit 1
fi
export ASSET_URL="${ASSET_URL:-$APP_URL}"
export SESSION_SECURE_COOKIE="${SESSION_SECURE_COOKIE:-true}"

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan config:clear --no-interaction
php artisan package:discover --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --class=PlanSeeder --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan storage:link --no-interaction
chown -R www-data:www-data storage bootstrap/cache

envsubst '${PORT}' < /etc/nginx/templates/render.conf.template > /etc/nginx/conf.d/render.conf
nginx -t
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/render.conf
