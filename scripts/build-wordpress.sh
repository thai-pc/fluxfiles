#!/bin/bash
#
# Build a self-contained WordPress plugin ZIP.
#
# The plugin needs the WordPress wrapper PLUS the FluxFiles core (api/, public/,
# assets/, lang/) and the browser SDK bundled together so end users only have to
# unzip into wp-content/plugins/. That layout matches what FluxFilesPlugin and
# FluxFilesApi look for at runtime (see `FluxFilesPlugin::corePath()`).
#
# Run from repo root:  bash scripts/build-wordpress.sh [X.Y.Z]
#
# With a version argument (the release workflow passes the one from the
# wordpress-vX.Y.Z tag) the plugin header and readme.txt Stable tag in the BUILT
# copy are rewritten to it. WordPress compares that header against whatever an
# update channel advertises, so a zip whose header lags its tag makes a site
# re-offer the same update forever. Same principle as pack-modules.php: the
# version comes from the tag, never from a number typed somewhere.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$ROOT_DIR/build"
PLUGIN_DIR="$BUILD_DIR/fluxfiles"
PLUGIN_VERSION="${1:-}"

CORE_DIR="$ROOT_DIR/packages/core"
WP_DIR="$ROOT_DIR/packages/wordpress"
SDK_DIR="$ROOT_DIR/packages/sdk"

echo "==> Cleaning the plugin build..."
# Only this script's own outputs: build/ also holds the paid-module zips and
# catalogue.json from scripts/pack-modules.php, which must survive a plugin build.
rm -rf "$PLUGIN_DIR" "$BUILD_DIR/fluxfiles.zip"
mkdir -p "$PLUGIN_DIR"

echo "==> Copying WordPress plugin source..."
# Use rsync so we get a clean copy that excludes any local dev artifacts
# (vendor/, node_modules/, .DS_Store, etc.) the contributor may have left in
# packages/wordpress while working.
# packages/wordpress/.distignore lists what stays out of the zip (tests, composer
# files, docs, ...).
rsync -a --exclude-from="$WP_DIR/.distignore" "$WP_DIR/" "$PLUGIN_DIR/"

# The core release this plugin build bundles: the nearest core-vX.Y.Z tag, without the
# prefix. Shown in Site Health / About so a support report names the bundled core.
# A release build (version argument) must resolve it: the checkout needs the tags
# (fetch-depth: 0), and a silent 'unknown' would ship in every official ZIP.
CORE_TAG="$(git -C "$ROOT_DIR" describe --tags --abbrev=0 --match 'core-v[0-9]*' 2>/dev/null || true)"
CORE_VERSION="${CORE_TAG#core-v}"
if ! printf '%s' "$CORE_VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    CORE_VERSION=""
fi
if [ -z "$CORE_VERSION" ]; then
    if [ -n "$PLUGIN_VERSION" ]; then
        echo "ERROR: no core-vX.Y.Z release tag is reachable, so the bundled core version cannot be stamped." >&2
        echo "       Fetch the tags (actions/checkout fetch-depth: 0 / git fetch --tags) and retry." >&2
        exit 1
    fi
    echo "WARN: no core-vX.Y.Z tag reachable; FLUXFILES_CORE_VERSION stays 'unknown' (dev build)." >&2
elif ! git -C "$ROOT_DIR" diff --quiet "$CORE_TAG"..HEAD -- \
        packages/core/api packages/core/public packages/core/assets packages/core/lang packages/core/composer.json; then
    # The bundled core differs from the tagged release, so the stamp would be a lie.
    if [ -n "$PLUGIN_VERSION" ]; then
        echo "ERROR: packages/core changed since $CORE_TAG; tag core first, then release the plugin." >&2
        exit 1
    fi
    echo "WARN: packages/core changed since $CORE_TAG; the stamped core version is approximate (dev build)." >&2
fi

if [ -n "$PLUGIN_VERSION" ]; then
    echo "==> Stamping version $PLUGIN_VERSION into the built plugin..."
    case "$PLUGIN_VERSION" in
        [0-9]*.[0-9]*.[0-9]*) ;;
        *) echo "ERROR: expected X.Y.Z, got '$PLUGIN_VERSION'" >&2; exit 1 ;;
    esac
fi
if [ -n "$PLUGIN_VERSION" ] || [ -n "$CORE_VERSION" ]; then
    python3 "$SCRIPT_DIR/stamp-wp-version.py" "$PLUGIN_DIR" "$PLUGIN_VERSION" "$CORE_VERSION"
fi

echo "==> Bundling FluxFiles core (api/, public/, lang/) into plugin..."
cp -r "$CORE_DIR/api"    "$PLUGIN_DIR/api"
cp -r "$CORE_DIR/public" "$PLUGIN_DIR/public"
cp -r "$CORE_DIR/lang"   "$PLUGIN_DIR/lang"

# Core files the plugin never runs. The standalone entry point and the code behind
# it (helpers.php is only required by index.php; licence/update client + key refresh (the free plugin makes no licence calls), SSO, demo mode, tus) are left out rather than guarded
# with ABSPATH checks. api/Db/ stays: WpDbMetadataHandler and FluxFilesCli use it.
# The share/intake pages are redirect stubs in core and are served by the paid modules.
for f in index helpers LicenseManager LicenseKeyFile LicenseRefresh UpdateClient OidcDiscovery SsoStateToken SsoBootToken DemoMode TusServer; do
    rm -f "$PLUGIN_DIR/api/$f.php"
done
rm -f "$PLUGIN_DIR/public/share.html" "$PLUGIN_DIR/public/intake.html"

# The UI page is read from disk by the REST route and served through it with a CSP +
# frame protection; it must never be openable directly under wp-content/plugins/. No
# .htaccess can ship (WordPress.org rejects hidden files) and nginx ignores it anyway,
# so the page is converted into a PHP template that exits unless WordPress loaded it
# (FluxFilesApi::serveUiHtml strips the guard line and serves the rest), and no
# .html file stays under public/. index.php answers a request for the directory itself.
python3 - "$PLUGIN_DIR/public/index.html" "$PLUGIN_DIR/templates/ui-index.php" <<'PY'
import sys
# Binary in and out: no newline translation or re-encoding, the page stays byte-identical.
html = open(sys.argv[1], 'rb').read()
open(sys.argv[2], 'wb').write(b"<?php defined('ABSPATH') || exit; // phpcs:disable WordPress.WP.EnqueuedResources -- standalone iframe document served by the REST route (own CSP), not a WordPress page ?>\n" + html)
PY
rm -f "$PLUGIN_DIR"/public/*.html
cat > "$PLUGIN_DIR/public/index.php" <<'PHP'
<?php
// The bundled UI pages are served through the plugin's REST route, never directly.
if (!defined('ABSPATH')) {
    http_response_code(403);
    exit;
}
exit;
PHP

echo "==> Bundling core UI assets (fm.js, fm.css) into plugin assets/..."
mkdir -p "$PLUGIN_DIR/assets"
cp "$CORE_DIR/assets/fm.js"  "$PLUGIN_DIR/assets/fm.js"
cp "$CORE_DIR/assets/fm.css" "$PLUGIN_DIR/assets/fm.css"
# The pre-minified pair the UI is actually served from (scripts/build-ui.sh); the
# plugin falls back to fm.js/fm.css above when they are absent or FLUXFILES_DEBUG is on.
for f in fm.min.js fm.min.css; do
    if [ -f "$CORE_DIR/assets/$f" ]; then cp "$CORE_DIR/assets/$f" "$PLUGIN_DIR/assets/$f"; fi
done

# Alpine, loaded by the page itself. Deliberately not on a CDN: it runs in the
# origin that holds the JWT. CodeMirror is NOT shipped: the code editor uses
# WordPress's bundled copy (wp-includes/js/codemirror/, see serveUiHtml()), so the
# plugin does not duplicate a core library.
mkdir -p "$PLUGIN_DIR/assets/vendor"
cp -r "$CORE_DIR/assets/vendor/alpine" "$PLUGIN_DIR/assets/vendor/alpine"
# WordPress ships CodeMirror but no dark theme, so the one theme fm.js uses in dark
# mode (MIT, credited in THIRD-PARTY-NOTICES.md) ships next to fm.js, not under
# assets/vendor/codemirror/.
cp "$CORE_DIR/assets/vendor/codemirror/theme/material-darker.min.css" "$PLUGIN_DIR/assets/codemirror-theme-material-darker.css"

# The watermark font: ImageOptimizer resolves api/../assets/fonts/DejaVuSans.ttf,
# so without this a text watermark has no font in the plugin build.
cp -r "$CORE_DIR/assets/fonts" "$PLUGIN_DIR/assets/fonts"

echo "==> Bundling browser SDK as assets/fluxfiles.js..."
cp "$SDK_DIR/fluxfiles.js" "$PLUGIN_DIR/assets/fluxfiles.js"

echo "==> Copying license + third-party notices..."
# No embed.php / modules-autoload.php: the plugin mints its own tokens, and paid
# modules are loaded by the separate FluxFiles Pro add-on (fluxfiles_module_paths).
[ -f "$ROOT_DIR/LICENSE" ] && cp "$ROOT_DIR/LICENSE" "$PLUGIN_DIR/LICENSE" || true
# WordPress.org allows only readme.txt/LICENSE-style files in the plugin root, so the
# extension-API reference and the notices live under docs/.
mkdir -p "$PLUGIN_DIR/docs"
cp "$WP_DIR/HOOKS.md" "$PLUGIN_DIR/docs/HOOKS.md"
# Licences of the shipped front-end libraries (assets/vendor/*/LICENSE ship with them).
# This build uses WordPress's CodeMirror, so that row is dropped from the notice.
python3 - "$CORE_DIR/THIRD-PARTY-NOTICES.md" "$PLUGIN_DIR/docs/THIRD-PARTY-NOTICES.md" "$PLUGIN_DIR/assets/THIRD-PARTY-NOTICES.txt" <<'PY'
import sys
lines = open(sys.argv[1]).read().split('\n')
out = [l for l in lines if not l.startswith('| [CodeMirror 5]')]
txt = '\n'.join(out).replace(
    '## Not bundled',
    '## Not bundled\n\nThe code editor loads the CodeMirror copy that WordPress itself ships\n(`wp-includes/js/codemirror/`); no CodeMirror files are part of this plugin.',
    1)
txt = txt.rstrip('\n') + """

## CodeMirror dark theme

`assets/codemirror-theme-material-darker.css` is the `material-darker` theme from
CodeMirror 5 (MIT, Copyright (C) 2017 Marijn Haverbeke and others, based on the
Material Theme by Mattia Astorino). It styles the code editor that runs on
WordPress's own CodeMirror in dark mode.
"""
open(sys.argv[2], 'w').write(txt)
# The same text is the UI's About > Third-party notices asset (served by the assets route).
open(sys.argv[3], 'w').write(txt)
PY

# The plugin's own composer.json declares its WP-side deps. We install with the
# core deps too so the plugin's vendor/ has firebase/php-jwt, league/flysystem,
# AWS SDK, Intervention, etc. We merge by temporarily using the core composer.
echo "==> Installing Composer dependencies (production only)..."
# Use the core composer.json (it has every runtime dep the plugin actually uses)
# but install into the plugin folder so we ship one vendor/ tree.
# The generated manifest SHIPS with the zip (WordPress.org review expects a
# composer.json + composer.lock describing the bundled vendor/). It is core's own
# manifest minus what does not exist in this build: autoload.files (modules-autoload.php),
# bin (bin/fluxfiles) and require-dev/autoload-dev. `composer update --lock` only
# refreshes the lock's content-hash for that edit; it does not change any version.
# (.distignore keeps the WP package's own composer.json/lock out of the rsync copy.)
php -r '
$j = json_decode(file_get_contents($argv[1]), true);
unset($j["autoload"]["files"], $j["bin"], $j["require-dev"], $j["autoload-dev"]);
if (empty($j["autoload"])) { unset($j["autoload"]); }
$j["name"] = "fluxfiles/wordpress-plugin-bundle";
$j["description"] = "Dependencies bundled in vendor/ of the FluxFiles WordPress plugin (generated from the FluxFiles core manifest).";
$j["type"] = "project";
file_put_contents($argv[2], json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$CORE_DIR/composer.json" "$PLUGIN_DIR/composer.json"
cp "$CORE_DIR/composer.lock" "$PLUGIN_DIR/composer.lock"
(
    cd "$PLUGIN_DIR"
    composer update --lock --no-interaction --no-scripts >/dev/null 2>&1 || true
    composer install --no-dev --optimize-autoloader --no-interaction
)

# Patch the plugin's autoload to also map FluxFiles\ to the bundled api/
echo "==> Registering FluxFiles\\ → api/ in vendor autoload..."
AUTOLOAD="$PLUGIN_DIR/vendor/autoload.php"
if ! grep -q 'FluxFiles core classes' "$AUTOLOAD"; then
    # Insert a PSR-4-style shim right before composer's getLoader() return.
    python3 - "$AUTOLOAD" << 'PY'
import sys
p = sys.argv[1]
shim = """
// FluxFiles core classes — bundled at <plugin>/api/ alongside vendor/
spl_autoload_register(static function ($class) {
    if (strpos($class, 'FluxFiles\\\\') !== 0) return;
    $rel = substr($class, strlen('FluxFiles\\\\'));
    $rel = str_replace('\\\\', '/', $rel) . '.php';
    $file = dirname(__DIR__) . '/api/' . $rel;
    if (is_file($file)) require $file;
});

"""
src = open(p).read()
src = src.replace('return ComposerAutoloaderInit', shim + 'return ComposerAutoloaderInit', 1)
open(p, 'w').write(src)
PY
fi

echo "==> Removing unnecessary files..."
rm -rf "$PLUGIN_DIR/vendor/bin"
find "$PLUGIN_DIR/vendor" -name ".git"   -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "tests"  -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "Tests"  -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "test"   -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" \( -name "docs" -o -name "doc" -o -name "examples" -o -name "example" -o -name ".github" \) -type d -exec rm -rf {} + 2>/dev/null || true
# Markdown is documentation, except the licence/notice files, which must ship.
find "$PLUGIN_DIR/vendor" -type f -iname "*.md" ! -iname "LICENSE*" ! -iname "NOTICE*" -delete 2>/dev/null || true
find "$PLUGIN_DIR/api" -type f -iname "*.md" -delete 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -type f \( -name ".git*" -o -name "phpunit*" -o -name ".editorconfig" -o -name ".php-cs-fixer*" -o -name "phpcs.xml*" -o -name "psalm*.xml*" -o -name "phpstan*" \) -delete 2>/dev/null || true
# WordPress.org rejects executables/archives and hidden files anywhere in the plugin
# (application_detected, hidden_files). Library helper scripts and bin/ launchers are never
# run by the plugin. paragonie/random_compat v9.99.x is an empty polyfill stub for PHP < 7
# (this build needs PHP >= 8.1): its other/ build helpers and psalm-autoload.php go; the
# stub's own lib/random.php (which composer still autoloads) stays.
find "$PLUGIN_DIR/vendor" -type d -name bin -prune -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -type f \( -name "*.sh" -o -name "*.phar" -o -name "*.exe" -o -name "*.bat" \) -delete 2>/dev/null || true
rm -rf "$PLUGIN_DIR/vendor/paragonie/random_compat/other"
rm -f  "$PLUGIN_DIR/vendor/paragonie/random_compat/psalm-autoload.php"
# Any leftover dot-file or dot-directory (.travis.yml, .gitkeep, .github, ...).
find "$PLUGIN_DIR" -mindepth 1 -name ".*" -prune -exec rm -rf {} + 2>/dev/null || true

echo "==> Creating ZIP archive..."
cd "$BUILD_DIR"
zip -qr fluxfiles.zip fluxfiles/ -x "*.DS_Store" "*__MACOSX*"

ZIP_SIZE=$(du -h "$BUILD_DIR/fluxfiles.zip" | cut -f1)
echo "==> Done: $BUILD_DIR/fluxfiles.zip ($ZIP_SIZE)"
