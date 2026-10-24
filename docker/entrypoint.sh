#!/bin/sh
set -e
ENV_FILE=/app/packages/core/.env
# Materialize config from container env once, for the CLI tools and anything
# that reads the file. The file is retained after the first boot. At runtime a
# container env var still wins over the same key in .env (EnvBootstrap), so
# changing `-e` values takes effect; a mounted .env supplies the keys the
# container env doesn't set.
if [ ! -f "$ENV_FILE" ]; then
    env | grep -E '^(FLUXFILES_|AWS_|R2_|SFTP_)' > "$ENV_FILE" || true
fi
php-fpm -D
exec nginx -g 'daemon off;'
