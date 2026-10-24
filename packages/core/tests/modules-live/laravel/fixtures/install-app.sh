#!/bin/bash
# Build-time: install the staged core + Laravel adapter into the Laravel app the
# way a customer does (Composer, copied — not symlinked — into vendor/), then
# drop each paid module into vendor/fluxfiles/<id>/ the way `fluxfiles update
# <module>` (UpdateClient) unpacks it. Nothing here adds a custom autoloader:
# core's own modules-autoload.php has to find the modules on its own.
set -euo pipefail
source /src/versions.env
cd /srv/laravel
composer config minimum-stability stable
composer config repositories.ff-core "{\"type\":\"path\",\"url\":\"/src/core\",\"options\":{\"symlink\":false,\"versions\":{\"fluxfiles/fluxfiles\":\"$CORE_V\"}}}"
composer config repositories.ff-laravel "{\"type\":\"path\",\"url\":\"/src/laravel\",\"options\":{\"symlink\":false,\"versions\":{\"fluxfiles/laravel\":\"$LARAVEL_V\"}}}"
composer require "fluxfiles/fluxfiles:$CORE_V" "fluxfiles/laravel:$LARAVEL_V" --no-interaction --no-progress
for m in /src/modules/*; do
  id="$(basename "$m")"
  mkdir -p "vendor/fluxfiles/$id" && cp -R "$m/src" "$m/composer.json" "vendor/fluxfiles/$id/"
done
php artisan vendor:publish --tag=fluxfiles-config --force >/dev/null
# ['web','auth'] → the stateless test guard (see LiveJwtUser).
perl -0pi -e "s/'middleware' => \[[^\]]*\],/'middleware' => [\\\\App\\\\Http\\\\Middleware\\\\LiveJwtUser::class],/" config/fluxfiles.php
grep -q "LiveJwtUser" config/fluxfiles.php
cat /fixtures-laravel/routes-web.append.php >> routes/web.php
cp -R /fixtures-laravel/app/. app/ && cp -R /fixtures-laravel/resources/. resources/
# The image must not carry the create-project .env's APP_KEY as the only one: a
# fresh key per build is fine (sessions are array-backed in this rig).
php artisan key:generate --force >/dev/null
chown -R www-data:www-data storage bootstrap/cache || true
