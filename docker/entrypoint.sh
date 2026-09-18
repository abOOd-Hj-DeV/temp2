#!/bin/sh
set -eu

# Warm framework caches; migrations are run explicitly by the `migrate` compose service.
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
