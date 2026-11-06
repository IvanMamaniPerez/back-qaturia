#!/bin/sh
set -eu
cd /var/www

# Named storage volumes start empty. Do not run migrations or create secrets here.
mkdir -p storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs bootstrap/cache

chown -R qaturia:qaturia storage bootstrap/cache

exec "$@"
