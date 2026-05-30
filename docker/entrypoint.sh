#!/bin/sh
set -e
ENV_FILE=/app/packages/core/.env
# Materialize config from container env (FLUXFILES_*, AWS_*, R2_*) into .env once.
if [ ! -f "$ENV_FILE" ]; then
    env | grep -E '^(FLUXFILES_|AWS_|R2_)' > "$ENV_FILE" || true
fi
php-fpm -D
exec nginx -g 'daemon off;'
