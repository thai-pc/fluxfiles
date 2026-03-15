#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$ROOT_DIR/build"
PLUGIN_DIR="$BUILD_DIR/fluxfiles"

echo "==> Cleaning build directory..."
rm -rf "$BUILD_DIR"
mkdir -p "$PLUGIN_DIR"

echo "==> Copying WordPress plugin files..."
cp -r "$ROOT_DIR/adapters/wordpress/"* "$PLUGIN_DIR/"

echo "==> Copying FluxFiles core..."
cp -r "$ROOT_DIR/api" "$PLUGIN_DIR/api"
cp -r "$ROOT_DIR/config" "$PLUGIN_DIR/config"
cp -r "$ROOT_DIR/lang" "$PLUGIN_DIR/lang"
cp -r "$ROOT_DIR/assets" "$PLUGIN_DIR/assets"
cp -r "$ROOT_DIR/public" "$PLUGIN_DIR/public"
cp "$ROOT_DIR/composer.json" "$PLUGIN_DIR/"
cp "$ROOT_DIR/composer.lock" "$PLUGIN_DIR/" 2>/dev/null || true
cp "$ROOT_DIR/embed.php" "$PLUGIN_DIR/"
cp "$ROOT_DIR/fluxfiles.js" "$PLUGIN_DIR/"
cp "$ROOT_DIR/LICENSE" "$PLUGIN_DIR/"

echo "==> Installing Composer dependencies (production only)..."
cd "$PLUGIN_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Removing unnecessary files..."
rm -rf "$PLUGIN_DIR/vendor/bin"
find "$PLUGIN_DIR/vendor" -name ".git" -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "tests" -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "Tests" -type d -exec rm -rf {} + 2>/dev/null || true
find "$PLUGIN_DIR/vendor" -name "test" -type d -exec rm -rf {} + 2>/dev/null || true

echo "==> Creating ZIP archive..."
cd "$BUILD_DIR"
zip -r fluxfiles.zip fluxfiles/ -x "*.DS_Store" "*__MACOSX*"

ZIP_SIZE=$(du -h "$BUILD_DIR/fluxfiles.zip" | cut -f1)
echo "==> Done! Build: $BUILD_DIR/fluxfiles.zip ($ZIP_SIZE)"
