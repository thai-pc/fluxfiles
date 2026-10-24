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

# Docker Desktop's osxfs/virtiofs cache keys on inode, and build-wordpress.sh
# rewrites build/fluxfiles wholesale — so on a RE-run the container keeps serving
# the previous build's files (same paths, new inodes) even across a full wp-env
# restart, and a freshly added REST route silently 404s. A write from inside the
# container is what invalidates those entries; `touch` is the cheapest one.
echo "==> Refreshing the plugin bind mount (busts Docker's stale file cache)"
npx --yes @wordpress/env run cli \
  sh -c 'find /var/www/html/wp-content/plugins/fluxfiles -type f -exec touch {} +' >/dev/null

echo "==> Activating plugin + configuring site"
run plugin activate fluxfiles >/dev/null 2>&1 || true
run option update fluxfiles_secret "$SECRET" >/dev/null
# Enable the experimental native-picker integration so the e2e can assert the
# "From FluxFiles" button is injected into wp.media on a real WordPress.
run option update fluxfiles_replace_picker 1 >/dev/null
# Gate the local disk so list() emits real /api/fm/stream + /api/fm/img URLs
# (what wp.spec.ts's stream/img test hits) instead of a static wp-content URL.
run option update fluxfiles_local_private 1 >/dev/null
# The e2e makes more than the proxy's default 10 writes/min per user (uploads +
# /attach calls back to back), so raise this test site's rate claims — the same
# move core's own test servers make with FLUXFILES_RATE_LIMIT_WRITE.
npx --yes @wordpress/env run cli sh -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/fluxfiles-e2e.php <<"PHP"
<?php
add_filter("fluxfiles_token_overrides", static function ($o) { return (array) $o + ["rate_read" => 10000, "rate_write" => 10000]; });
PHP' >/dev/null
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
