#!/bin/bash
#
# Build a self-contained WordPress plugin ZIP.
#
# The plugin needs the WordPress wrapper PLUS the FluxFiles core (api/, public/,
# assets/, lang/) and the browser SDK bundled together so end users only have to
# unzip into wp-content/plugins/. That layout matches what FluxFilesPlugin and
# FluxFilesApi look for at runtime (see `FluxFilesPlugin::corePath()`).
#
# Run from repo root:  bash scripts/build-wordpress.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$ROOT_DIR/build"
PLUGIN_DIR="$BUILD_DIR/fluxfiles"

CORE_DIR="$ROOT_DIR/packages/core"
WP_DIR="$ROOT_DIR/packages/wordpress"
SDK_DIR="$ROOT_DIR/packages/sdk"

echo "==> Cleaning build directory..."
rm -rf "$BUILD_DIR"
mkdir -p "$PLUGIN_DIR"

echo "==> Copying WordPress plugin source..."
# Use rsync so we get a clean copy that excludes any local dev artifacts
# (vendor/, node_modules/, .DS_Store, etc.) the contributor may have left in
# packages/wordpress while working.
rsync -a \
    --exclude='vendor/' \
    --exclude='node_modules/' \
    --exclude='.DS_Store' \
    --exclude='build/' \
    "$WP_DIR/" "$PLUGIN_DIR/"

echo "==> Bundling FluxFiles core (api/, public/, lang/) into plugin..."
cp -r "$CORE_DIR/api"    "$PLUGIN_DIR/api"
cp -r "$CORE_DIR/public" "$PLUGIN_DIR/public"
cp -r "$CORE_DIR/lang"   "$PLUGIN_DIR/lang"
cp -r "$CORE_DIR/config" "$PLUGIN_DIR/config"

echo "==> Bundling core UI assets (fm.js, fm.css) into plugin assets/..."
mkdir -p "$PLUGIN_DIR/assets"
cp "$CORE_DIR/assets/fm.js"  "$PLUGIN_DIR/assets/fm.js"
cp "$CORE_DIR/assets/fm.css" "$PLUGIN_DIR/assets/fm.css"

echo "==> Bundling browser SDK as assets/fluxfiles.js..."
cp "$SDK_DIR/fluxfiles.js" "$PLUGIN_DIR/assets/fluxfiles.js"

echo "==> Copying core embed helper + license..."
cp "$CORE_DIR/embed.php" "$PLUGIN_DIR/embed.php"
# The paid-module autoloader. Composer's `autoload.files` (from the core
# composer.json used below) references it at the plugin root, and the layout it
# probes — <plugin>/vendor/fluxfiles/<module>/src/ — is exactly where ACTIVATE.md
# tells a WordPress customer to unpack the zip. Without this file the plugin loads,
# the licence verifies, and every paid gate still answers 501.
cp "$CORE_DIR/modules-autoload.php" "$PLUGIN_DIR/modules-autoload.php"
[ -f "$ROOT_DIR/LICENSE" ] && cp "$ROOT_DIR/LICENSE" "$PLUGIN_DIR/LICENSE" || true

# The plugin's own composer.json declares its WP-side deps. We install with the
# core deps too so the plugin's vendor/ has firebase/php-jwt, league/flysystem,
# AWS SDK, Intervention, etc. We merge by temporarily using the core composer.
echo "==> Installing Composer dependencies (production only)..."
# Use the core composer.json (it has every runtime dep the plugin actually uses)
# but install into the plugin folder so we ship one vendor/ tree.
cp "$CORE_DIR/composer.json" "$PLUGIN_DIR/composer.json.core"
cp "$CORE_DIR/composer.lock" "$PLUGIN_DIR/composer.lock.core" 2>/dev/null || true
(
    cd "$PLUGIN_DIR"
    # Run composer install with core deps. The WP package's own composer.json
    # stays untouched (it's only used by Packagist consumers).
    mv composer.json composer.json.wp
    mv composer.json.core composer.json
    [ -f composer.lock.core ] && mv composer.lock.core composer.lock || true
    composer install --no-dev --optimize-autoloader --no-interaction
    mv composer.json composer.json.core
    mv composer.json.wp composer.json
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
rm -f  "$PLUGIN_DIR/composer.lock" "$PLUGIN_DIR/composer.json.core"
find "$PLUGIN_DIR/vendor" -name ".git"   -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "tests"  -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "Tests"  -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "test"   -type d -exec rm -rf {} + 2>/dev/null || true

echo "==> Creating ZIP archive..."
cd "$BUILD_DIR"
zip -qr fluxfiles.zip fluxfiles/ -x "*.DS_Store" "*__MACOSX*"

ZIP_SIZE=$(du -h "$BUILD_DIR/fluxfiles.zip" | cut -f1)
echo "==> Done: $BUILD_DIR/fluxfiles.zip ($ZIP_SIZE)"
