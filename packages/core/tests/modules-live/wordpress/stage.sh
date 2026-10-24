#!/bin/bash
# Stage the WordPress live rig from a COPY of the repo (never the live tree, never
# the dev .env): build the real plugin artifact with scripts/build-wordpress.sh,
# install the 13 paid modules into it like a customer would (unzip into
# <plugin>/vendor/fluxfiles/<id>/, via UpdateClient::install), and write the
# container env with a freshly minted license. Prints neither key nor license.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../../../../.." && pwd)"
ST="$HERE/.stage"; rm -rf "$ST/repo" "$ST/plugin" "$ST/zips"; mkdir -p "$ST/repo/packages" "$ST/repo/scripts"

# A minimal repo copy that build-wordpress.sh can run in (it rm -rf's <root>/build,
# so running it in the real repo would yank build/fluxfiles out from under wp-env).
cp "$REPO/scripts/build-wordpress.sh" "$REPO/scripts/stamp-wp-version.py" "$ST/repo/scripts/"
[ -f "$REPO/LICENSE" ] && cp "$REPO/LICENSE" "$ST/repo/"
rsync -a --exclude .env --exclude 'storage/*' --exclude node_modules --exclude tests --exclude test-results --exclude /vendor \
  "$REPO/packages/core/" "$ST/repo/packages/core/"
rsync -a --exclude /vendor --exclude node_modules --exclude /tests "$REPO/packages/wordpress/" "$ST/repo/packages/wordpress/"
mkdir -p "$ST/repo/packages/sdk" && cp "$REPO/packages/sdk/fluxfiles.js" "$ST/repo/packages/sdk/"
echo "==> build-wordpress.sh (staged copy)"
bash "$ST/repo/scripts/build-wordpress.sh" >"$ST/build.log" 2>&1 || { tail -30 "$ST/build.log"; exit 1; }
mv "$ST/repo/build/fluxfiles" "$ST/plugin"

echo "==> installing modules"
php "$HERE/install-modules.php" "$REPO" "$ST/plugin" "$ST/zips"

php "$HERE/../mint-license.php" "$REPO" "$ST/license.txt"   # never echoes the key or the license
SECRET="$(cat "$ST/secret.txt" 2>/dev/null || true)"
if [ -z "$SECRET" ]; then SECRET="ff-live-wp-$(openssl rand -hex 20)"; printf '%s' "$SECRET" > "$ST/secret.txt"; fi
umask 077
# FF_E2E_* are consumed by the entrypoint (wp-cli writes them into WP OPTIONS — the
# documented WordPress path). FLUXFILES_LICENSE_KEY is deliberately NOT set in the
# env, so the run proves the Settings → Licence option is what the plugin uses.
# Engine settings (AI Vision, DLP, webhooks) go through the "Module engines" option
# (fixtures/module-env.json, see the entrypoint); only the C2PA manifest stays a real
# env var, to prove an env var coexists with the option.
cat > "$ST/wp.env" <<ENV
FF_E2E_SECRET=$SECRET
FF_E2E_LICENSE=$(cat "$ST/license.txt")
FLUXFILES_C2PA_MANIFEST=/fixtures/c2pa-manifest.json
ENV
echo "staged plugin: $(find "$ST/plugin" -type f | wc -l | tr -d ' ') files"
