#!/bin/bash
# Stage a clean copy of what a real Laravel host would install — core (as the
# fluxfiles-core split ships it: packages/core, no vendor/, no sibling sdk/),
# the Laravel adapter, and the 13 paid-module sources — plus the server env with
# a freshly minted license. Never the dev .env, never runtime storage; nothing
# here is bind-mounted, the image is built from this copy.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; LIVE="$(cd "$HERE/.." && pwd)"; REPO="$(cd "$LIVE/../../../.." && pwd)"
ST="$HERE/.stage"; rm -rf "$ST/src"; mkdir -p "$ST/src/core" "$ST/src/laravel" "$ST/src/modules"
# Anchored (/vendor, /tests): an unanchored 'vendor' also dropped core's
# assets/vendor/ (vendored Alpine, xterm, CodeMirror), so the UI never booted.
EXCL=(--exclude .env --exclude 'storage/*' --exclude node_modules --exclude /tests --exclude test-results --exclude /vendor --exclude /composer.lock)
rsync -a --delete "${EXCL[@]}" "$REPO/packages/core/" "$ST/src/core/"
mkdir -p "$ST/src/core/storage"
rsync -a --delete "${EXCL[@]}" "$REPO/packages/laravel/" "$ST/src/laravel/"
for m in share intake versioning webhooks ai ocr virus backup c2pa audit-export sso dlp legal-hold; do
  [ -d "$REPO/packages/$m/src" ] || { echo "module $m not checked out" >&2; exit 2; }
  mkdir -p "$ST/src/modules/$m" && rsync -a "$REPO/packages/$m/src" "$REPO/packages/$m/composer.json" "$ST/src/modules/$m/"
done
# Versions the path repos advertise: the newest real tags, so a module or adapter
# floor above what core has actually released fails `composer require` here.
CORE_V="$(git -C "$REPO" tag | grep '^core-v' | sort -V | tail -1 | sed 's/^core-v//')"
LARAVEL_V="$(git -C "$REPO" tag | grep '^laravel-v' | sort -V | tail -1 | sed 's/^laravel-v//')"
printf 'CORE_V=%s\nLARAVEL_V=%s\n' "$CORE_V" "$LARAVEL_V" > "$ST/src/versions.env"
php "$LIVE/mint-license.php" "$REPO" "$ST/license.txt"   # never echoes the key or the license
SECRET="$(cat "$ST/secret.txt" 2>/dev/null || true)"
if [ -z "$SECRET" ]; then SECRET="ff-live-laravel-$(openssl rand -hex 20)"; printf '%s' "$SECRET" > "$ST/secret.txt"; fi
umask 077
cat > "$ST/laravel.env" <<ENV
APP_URL=http://localhost:8290
APP_ENV=local
APP_DEBUG=true
SESSION_DRIVER=array
CACHE_STORE=array
LOG_CHANNEL=stderr
FLUXFILES_MODE=proxy
FLUXFILES_SECRET=$SECRET
FLUXFILES_LICENSE_KEY=$(cat "$ST/license.txt")
FLUXFILES_LOCAL_ROOT=/srv/laravel/storage/app/fluxfiles-uploads
FLUXFILES_LOCAL_PRIVATE=true
FLUXFILES_RATE_LIMIT_READ=100000
FLUXFILES_RATE_LIMIT_WRITE=100000
AWS_BUCKET=ff-backup
AWS_ACCESS_KEY_ID=test
AWS_SECRET_ACCESS_KEY=test
AWS_DEFAULT_REGION=us-east-1
AWS_ENDPOINT=http://localstack:4566
FLUXFILES_WEBHOOK_ALLOW_INTERNAL=true
FLUXFILES_AIVISION_PROVIDER=http
FLUXFILES_AIVISION_ENDPOINT=http://ff-ai:8192/
FLUXFILES_DLP_ENDPOINT=http://ff-presidio:3000
FLUXFILES_C2PA_MANIFEST=/fixtures/c2pa-manifest.json
ENV
echo "staged: $(find "$ST/src" -type f | wc -l | tr -d ' ') files (core $CORE_V, laravel $LARAVEL_V)"
