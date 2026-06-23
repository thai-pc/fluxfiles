#!/usr/bin/env bash
# Scaffold the REAL Laravel proxy app used by the adapter Playwright e2e.
# The generated app (../laravel-app) is gitignored; this script + ./files/ are the
# committed source of truth, so anyone can regenerate it with one command:
#
#   bash packages/core/tests/apps/laravel-e2e/setup.sh
#
# It wires the local packages/core + packages/laravel via Composer path repos,
# configures proxy mode with a stateless-JWT user guard (E2eJwtUser), and symlinks
# the core UI + SDK into an ff-base dir. Idempotent — safe to re-run.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPS="$(cd "$HERE/.." && pwd)"          # packages/core/tests/apps
REPO="$(cd "$APPS/../../../.." && pwd)" # repo root
APP="$APPS/laravel-app"
CORE="$REPO/packages/core"
LARAVEL="$REPO/packages/laravel"
SDK="$REPO/packages/sdk"
SECRET="laravel-e2e-secret-0123456789abcdefgh"

echo "==> Scaffolding Laravel app at $APP"
[ -f "$APP/artisan" ] || composer create-project laravel/laravel "$APP" --prefer-dist --no-interaction

cd "$APP"
echo "==> Wiring Composer path repositories"
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.fluxfiles-core path "$CORE"
composer config repositories.fluxfiles-laravel path "$LARAVEL"

# Resolve the local packages' dev branch to a version the adapter constraint accepts.
BRANCH="$(cd "$CORE" && git rev-parse --abbrev-ref HEAD)"
composer require "fluxfiles/fluxfiles:dev-${BRANCH} as 0.2.36" "fluxfiles/laravel:dev-${BRANCH}" --no-interaction

echo "==> Publishing + patching config (proxy mode, JWT-user middleware)"
php artisan vendor:publish --tag=fluxfiles-config --force >/dev/null
# Swap the default ['web','auth'] middleware for the stateless E2eJwtUser guard.
perl -0pi -e "s/'middleware' => \[[^\]]*\],/'middleware' => [\\\\App\\\\Http\\\\Middleware\\\\E2eJwtUser::class],/" config/fluxfiles.php

echo "==> Copying committed app files (middleware, view)"
cp -R "$HERE/files/." "$APP/"

echo "==> Adding the /files route"
if ! grep -q "GenericUser" routes/web.php; then
  cat >> routes/web.php <<'PHP'

// FluxFiles e2e: a page embedding the real <x-fluxfiles> component. A fake user
// lets FluxFilesManager::tokenForUser() mint a JWT without a DB-backed login.
Route::get('/files', function () {
    auth()->setUser(new \Illuminate\Auth\GenericUser(['id' => 'laravel-e2e']));
    return view('files');
});
PHP
fi

echo "==> Configuring .env"
sed -i.bak 's#^APP_URL=.*#APP_URL=http://127.0.0.1:8000#' .env && rm -f .env.bak
LOCALROOT="$APP/storage/app/fluxfiles-uploads"
mkdir -p "$LOCALROOT"
if ! grep -q "FluxFiles e2e" .env; then
  cat >> .env <<EOF

# --- FluxFiles e2e ---
FLUXFILES_SECRET=$SECRET
FLUXFILES_MODE=proxy
FLUXFILES_BASE_PATH=$APP/ff-base
FLUXFILES_LOCAL_ROOT=$LOCALROOT
FLUXFILES_RATE_LIMIT_READ=10000
FLUXFILES_RATE_LIMIT_WRITE=10000
EOF
fi

echo "==> Symlinking ff-base (core UI + assets + SDK)"
rm -rf ff-base && mkdir -p ff-base
ln -s "$CORE/public" ff-base/public
ln -s "$CORE/assets" ff-base/assets
ln -s "$SDK/fluxfiles.js" ff-base/fluxfiles.js

php artisan config:clear >/dev/null
echo "==> Done. Run the e2e with:  cd $APPS && npm run e2e"
