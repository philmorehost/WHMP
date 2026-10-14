#!/bin/sh
#
# Container entrypoint: make the app runnable the moment the stack is up.
#
#   1. composer install when vendor/ is missing (a bind-mounted checkout has no
#      vendor/; the image's copy is hidden by the mount).
#   2. wait for the database to answer.
#   3. run the migrations once (the request path also runs them, but doing it
#      here means `docker compose up -d` is ready before the first browser hit).
#
# Every step is safe to repeat: the container restarts do not rebuild anything.
set -eu

cd /var/www/html

if [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
    echo "[entrypoint] vendor/ is missing — running composer install"
    composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader
fi

mkdir -p storage/cache storage/sessions storage/system storage/backups \
    storage/ticket_attachments storage/logs build/coverage
chown -R www-data:www-data storage build 2>/dev/null || true

if [ -n "${DB_HOST:-}" ]; then
    printf '[entrypoint] waiting for the database at %s:%s ' "${DB_HOST}" "${DB_PORT:-3306}"
    i=0
    while [ "$i" -lt 60 ]; do
        if php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) (getenv("DB_PORT") ?: 3306)) ? 0 : 1);' 2>/dev/null; then
            echo "— up"
            break
        fi
        printf '.'
        i=$((i + 1))
        sleep 1
    done

    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] database did not answer in 60s; continuing (the web path retries)"
    elif [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
        echo "[entrypoint] running migrations"
        php bin/migrate.php || echo "[entrypoint] migrate.php hit a problem — see the output above; the request path retries"
    fi
fi

exec "$@"
