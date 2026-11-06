#!/bin/sh
set -eu
cd /var/www

mode="${APP_HTTP_SERVER:-artisan}"

case "$mode" in
  artisan)
    if [ "${APP_ENV:-local}" = "production" ]; then
      echo >&2 "ERROR: artisan serve is a development fallback. Use APP_HTTP_SERVER=octane in production."
      exit 78
    fi
    echo "QATUR IA: starting Laravel development HTTP server on 127.0.0.1:8000"
    exec php artisan serve --host=127.0.0.1 --port=8000
    ;;
  octane)
    if ! php -m | grep -qi '^swoole$'; then
      echo >&2 "ERROR: PHP 8.4 Swoole extension is not loaded."
      exit 78
    fi
    if ! php artisan list --raw | grep -q '^octane:start '; then
      echo >&2 "ERROR: Laravel Octane is not installed. Run composer require laravel/octane && php artisan octane:install --server=swoole."
      exit 78
    fi
    echo "QATUR IA: starting Laravel Octane (Swoole) on 127.0.0.1:8000"
    set -- php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000 \
      --workers="${OCTANE_WORKERS:-2}" --task-workers="${OCTANE_TASK_WORKERS:-2}" \
      --max-requests="${OCTANE_MAX_REQUESTS:-500}"
    if [ "${OCTANE_WATCH:-false}" = "true" ]; then
      set -- "$@" --watch
    fi
    exec "$@"
    ;;
  *)
    echo >&2 "ERROR: APP_HTTP_SERVER must be artisan or octane (received: $mode)."
    exit 78
    ;;
esac
