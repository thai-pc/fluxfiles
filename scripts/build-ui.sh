#!/bin/bash
#
# Build the pre-minified UI assets: packages/core/assets/fm.min.js + fm.min.css.
#
# The readable sources (fm.js / fm.css) stay the source of truth and operators still
# need no build step: the server (core index.php, the Laravel and WordPress proxies)
# serves fm.min.* when it exists and falls back to the sources otherwise, or when
# FLUXFILES_DEBUG=1. The minified files are COMMITTED; CI re-runs this script and
# fails when they differ (the "ui-min-fresh" job), so edit fm.js/fm.css, then run:
#
#   bash scripts/build-ui.sh
#
# esbuild is pinned so the output is byte-reproducible. Minify only: no bundling, no
# format change, no property mangling (index.html's Alpine expressions reference
# component members by name) and top-level names (fluxFilesApp) are left alone.
set -euo pipefail

ESBUILD_VERSION="0.28.0"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ASSETS="$ROOT_DIR/packages/core/assets"

run_esbuild() {
    if [ -x "$ROOT_DIR/node_modules/.bin/esbuild" ] \
        && [ "$("$ROOT_DIR/node_modules/.bin/esbuild" --version)" = "$ESBUILD_VERSION" ]; then
        "$ROOT_DIR/node_modules/.bin/esbuild" "$@"
    else
        npx --yes "esbuild@$ESBUILD_VERSION" "$@"
    fi
}

# Stamp the vendor trees' content hashes into fm.js (the ?v= of the lazy CodeMirror files).
node "$ROOT_DIR/scripts/vendor-ver.mjs"

cd "$ASSETS"
run_esbuild fm.js  --minify --legal-comments=none --log-level=warning --outfile=fm.min.js
run_esbuild fm.css --minify --legal-comments=none --log-level=warning --outfile=fm.min.css

# Sanity: the page and the adapters reach into these by name.
grep -q 'function fluxFilesApp' fm.min.js || { echo "ERROR: fluxFilesApp was renamed/dropped by minify" >&2; exit 1; }
grep -q 'FM_COLLATOR' fm.min.js || { echo "ERROR: top-level declarations were renamed by minify" >&2; exit 1; }

echo "==> fm.min.js $(wc -c < fm.min.js | tr -d ' ') bytes, fm.min.css $(wc -c < fm.min.css | tr -d ' ') bytes"
