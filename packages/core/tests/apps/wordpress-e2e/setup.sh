#!/usr/bin/env bash
# Bring up the REAL WordPress plugin e2e environment (Docker via @wordpress/env).
#
#   bash packages/core/tests/apps/wordpress-e2e/setup.sh
#
# Builds the self-contained plugin (scripts/build-wordpress.sh → build/fluxfiles,
# the actual release artifact), starts wp-env, then configures the site: activate
# the plugin, set the JWT secret, enable pretty permalinks, and publish a /files
# page carrying the [fluxfiles] shortcode. Idempotent. Requires Docker running.
#
# Tear down with:  npx @wordpress/env stop   (or `destroy` to wipe the DB)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../../../.." && pwd)"
SECRET="wp-e2e-secret-0123456789abcdefgh"

echo "==> Building the self-contained WordPress plugin (build/fluxfiles)"
bash "$REPO/scripts/build-wordpress.sh" >/dev/null

cd "$HERE"
echo "==> Starting wp-env (Docker; first run pulls WordPress + MySQL images)"
npx --yes @wordpress/env start

run() { npx --yes @wordpress/env run cli wp "$@"; }

echo "==> Activating plugin + configuring site"
run plugin activate fluxfiles >/dev/null 2>&1 || true
run option update fluxfiles_secret "$SECRET" >/dev/null
run rewrite structure '/%postname%/' --hard >/dev/null 2>&1
run rewrite flush --hard >/dev/null 2>&1

# Publish the /files page once (the shortcode embeds the manager in browser mode).
if ! run post list --post_type=page --name=files --field=ID --format=ids 2>/dev/null | grep -q '[0-9]'; then
  run post create --post_type=page --post_status=publish --post_title='Files' \
    --post_name='files' \
    --post_content='[fluxfiles disk="local" mode="browser" height="500px"]' --porcelain >/dev/null
fi

echo "==> Done. Site: http://localhost:8888/files/  (admin/password)"
echo "    Run the e2e:  cd $(cd "$HERE/.." && pwd) && npm run e2e:wp"
