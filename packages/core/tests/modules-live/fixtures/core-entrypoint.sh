#!/bin/bash
# clamd first (the virus module's clamdscan talks to it), then the core server.
set -e
clamd &
for i in $(seq 1 120); do [ -S /run/clamav/clamd.ctl ] && break; sleep 1; done
[ -S /run/clamav/clamd.ctl ] && echo "clamd ready after ${i}s" || echo "WARNING: clamd did not start"
mkdir -p /app/core/storage/uploads
exec php -S 0.0.0.0:8190 -t /app/core /fixtures/router.php
