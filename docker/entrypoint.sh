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
# Pool sizing (docker/php-fpm-pool.conf reads these); defaults suit a ~2 GB container.
export FPM_MAX_CHILDREN="${FPM_MAX_CHILDREN:-24}" FPM_START_SERVERS="${FPM_START_SERVERS:-4}" \
       FPM_MIN_SPARE_SERVERS="${FPM_MIN_SPARE_SERVERS:-2}" FPM_MAX_SPARE_SERVERS="${FPM_MAX_SPARE_SERVERS:-8}" \
       FPM_MAX_REQUESTS="${FPM_MAX_REQUESTS:-500}"
php-fpm -D
exec nginx -g 'daemon off;'
