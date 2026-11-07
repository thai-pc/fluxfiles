#!/usr/bin/env bash
# Bring up a REAL multisite WordPress (Docker via @wordpress/env) with FluxFiles
# NETWORK-activated, then create a sub-site AFTER activation (wp_initialize_site).
#
#   bash packages/core/tests/apps/wordpress-e2e/multisite/setup.sh
#
# Separate from the single-site env (port 8890, own wp-env project). Assumes the
# plugin is already built (build/fluxfiles, e.g. by ../setup.sh). Tear down with
#   cd packages/core/tests/apps/wordpress-e2e/multisite && npx @wordpress/env destroy
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../../../../.." && pwd)"
[ -f "$REPO/build/fluxfiles/fluxfiles.php" ] || bash "$REPO/scripts/build-wordpress.sh" >/dev/null

cd "$HERE"
npx --yes @wordpress/env start
run() { npx --yes @wordpress/env run cli wp "$@"; }
npx --yes @wordpress/env run cli \
  sh -c 'find /var/www/html/wp-content/plugins/fluxfiles -type f -exec touch {} +' >/dev/null

# Network-activate, with a sub-site that already exists (created BEFORE activation).
run plugin deactivate fluxfiles --network >/dev/null 2>&1 || true
run site list --field=blog_id 2>/dev/null | grep -qx 2 || run site create --slug=before --title='Before' >/dev/null
run plugin activate fluxfiles --network >/dev/null
# A sub-site created AFTER network activation.
run site list --field=blog_id 2>/dev/null | grep -qx 3 || run site create --slug=after --title='After' >/dev/null
run rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
echo "==> Done. Network: http://localhost:8890/  (admin/password), sub-sites /before/ and /after/"
