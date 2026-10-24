#!/bin/bash
# Stage a clean copy of core + the 13 paid modules for the image (never the dev
# .env, never runtime storage), and the server env with a freshly minted license.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../../../.." && pwd)"
ST="$HERE/.stage"; rm -rf "$ST/app"; mkdir -p "$ST/app/core" "$ST/app/modules"
rsync -a --delete --exclude .env --exclude 'storage/*' --exclude node_modules --exclude tests --exclude test-results \
  "$REPO/packages/core/" "$ST/app/core/"
mkdir -p "$ST/app/core/storage"
for m in share intake versioning webhooks ai ocr virus backup c2pa audit-export sso dlp legal-hold; do
  [ -d "$REPO/packages/$m/src" ] || { echo "module $m not checked out" >&2; exit 2; }
  mkdir -p "$ST/app/modules/$m" && rsync -a "$REPO/packages/$m/src" "$REPO/packages/$m/composer.json" "$ST/app/modules/$m/"
done
php "$HERE/mint-license.php" "$REPO" "$ST/license.txt"   # never echoes the key or the license
SECRET="$(cat "$ST/secret.txt" 2>/dev/null || true)"
if [ -z "$SECRET" ]; then SECRET="ff-live-$(openssl rand -hex 20)"; printf '%s' "$SECRET" > "$ST/secret.txt"; fi
umask 077
cat > "$ST/core.env" <<ENV
FLUXFILES_SECRET=$SECRET
FLUXFILES_LICENSE_KEY=$(cat "$ST/license.txt")
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
FLUXFILES_SSO_ENABLED=true
FLUXFILES_SSO_OIDC_ISSUER=http://ff-idp:8191
FLUXFILES_SSO_OIDC_CLIENT_ID=fluxfiles-live
FLUXFILES_SSO_OIDC_CLIENT_SECRET=live-client-secret
FLUXFILES_SSO_OIDC_REDIRECT_URI=http://localhost:8190/api/fm/sso/callback
FLUXFILES_SSO_DEFAULT_CLAIMS={"perms":["read","write","delete"],"disks":["local"]}
ENV
echo "staged: $(find "$ST/app" -type f | wc -l | tr -d ' ') files"
