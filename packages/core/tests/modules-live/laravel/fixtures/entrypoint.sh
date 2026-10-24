#!/bin/bash
# clamd first (the virus module's clamdscan talks to it — it must run in the
# container that executes the PHP), then Laravel on PHP's built-in server.
set -e
clamd &
for i in $(seq 1 120); do [ -S /run/clamav/clamd.ctl ] && break; sleep 1; done
[ -S /run/clamav/clamd.ctl ] && echo "clamd ready after ${i}s" || echo "WARNING: clamd did not start"
mkdir -p /srv/laravel/storage/app/fluxfiles-uploads /srv/laravel/storage/fluxfiles
cd /srv/laravel/public
export PHP_CLI_SERVER_WORKERS=4
exec php -S 0.0.0.0:8290 /srv/laravel/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
