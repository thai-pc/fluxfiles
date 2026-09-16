#!/bin/sh
set -e
ENV_FILE=/app/packages/core/.env
# Materialize config from container env once. The file is deliberately retained
# after the first boot so an explicitly mounted .env remains authoritative.
if [ ! -f "$ENV_FILE" ]; then
    env | grep -E '^(FLUXFILES_|AWS_|R2_|SFTP_)' > "$ENV_FILE" || true
fi
php-fpm -D
exec nginx -g 'daemon off;'
