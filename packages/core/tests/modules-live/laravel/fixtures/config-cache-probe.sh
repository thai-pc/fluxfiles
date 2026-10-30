#!/bin/bash
# The production layout most Laravel hosts run: every FLUXFILES_* value lives ONLY
# in .env (not in the process environment) and `php artisan config:cache` is on,
# so Laravel never loads .env at runtime. Starts a second server with an EMPTY
# environment on 127.0.0.1:8299, asks it for the license, and prints one JSON line. Prints no secret; restores .env and the
# config cache afterwards.
set -uo pipefail
cd /srv/laravel
cp .env /tmp/probe-env.bak
{
  echo
  env | grep -E '^(FLUXFILES_|AWS_)'
} >> .env
TOKEN=$(php -r 'require "vendor/autoload.php"; echo \FluxFiles\JwtCompat::encode(["sub"=>"cache-probe","iat"=>time(),"exp"=>time()+600,"perms"=>["read","write"],"disks"=>["local"]], getenv("FLUXFILES_SECRET"));')
php artisan config:cache >/dev/null
( cd public && exec env -i PATH="$PATH" HOME=/root php -S 127.0.0.1:8299 /srv/laravel/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1 ) &
SRV=$!
for i in $(seq 1 40); do curl -s -o /dev/null http://127.0.0.1:8299/public/index.html && break; sleep 0.25; done
LIC=$(curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8299/api/fm/license)
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null
php artisan config:clear >/dev/null
cp /tmp/probe-env.bak .env && rm -f /tmp/probe-env.bak
php -r '$l=json_decode($argv[1],true);
  echo json_encode(["edition"=>$l["data"]["edition"]??null, "status"=>$l["data"]["status"]??null]), "\n";' "$LIC"
