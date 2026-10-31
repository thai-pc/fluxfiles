#!/bin/bash
# SFTP through the real adapters: see ../README.md. Prints neither secrets nor license.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; LIVE="$(cd "$HERE/.." && pwd)"
LC=(docker compose -p ff-live-laravel -f docker-compose.laravel.yml)
WC=(docker compose -p ff-live-wp -f docker-compose.wordpress.yml)
L=ff-live-laravel-laravel-1; W=ff-live-wp-wp-1; SFTP=ff-sftp-adapters
LA=(/srv/laravel/vendor/autoload.php http://localhost:8290/api/fm)
WA=(/var/www/html/wp-content/plugins/fluxfiles/vendor/autoload.php http://localhost:8390/wp-json/fluxfiles/v1/api/fm)

cleanup() {
  docker rm -f "$SFTP" >/dev/null 2>&1
  (cd "$LIVE/laravel" && "${LC[@]}" down -v >/dev/null 2>&1)
  (cd "$LIVE/wordpress" && "${WC[@]}" down -v >/dev/null 2>&1)
}
trap cleanup EXIT

(cd "$LIVE/laravel" && bash stage.sh >/dev/null && echo 'FLUXFILES_SSRF_ALLOW_HOSTS=ff-sftp' >> .stage/laravel.env \
  && "${LC[@]}" up -d --build --no-deps laravel >/dev/null 2>&1) || { echo "laravel rig failed"; exit 2; }
(cd "$LIVE/wordpress" && bash stage.sh >/dev/null && echo 'FLUXFILES_SSRF_ALLOW_HOSTS=ff-sftp' >> .stage/wp.env \
  && "${WC[@]}" up -d --build --no-deps ff-db wp >/dev/null 2>&1) || { echo "wordpress rig failed"; exit 2; }
docker rm -f "$SFTP" >/dev/null 2>&1
docker run -d --name "$SFTP" --network ff-live-laravel_default --network-alias ff-sftp atmoz/sftp ff:ffpass:::upload >/dev/null || exit 2
docker network connect --alias ff-sftp ff-live-wp_default "$SFTP" || exit 2

wait_for() { for _ in $(seq 1 150); do [ "$(docker exec "$1" curl -s -o /dev/null -w '%{http_code}' "$2")" = 200 ] && return 0; sleep 2; done; echo "$1 did not come up"; exit 2; }
wait_for "$L" http://localhost:8290/public/index.html
wait_for "$W" http://localhost:8390/wp-json/
sleep 3

docker cp -q "$HERE/check.php" "$L:/tmp/check.php"; docker cp -q "$HERE/check.php" "$W:/tmp/check.php"
# The operator-configured disk: what config('fluxfiles.disks') holds on a real install.
docker exec "$L" php -r '$f="/srv/laravel/config/fluxfiles.php";$s=file_get_contents($f);$e=var_export(["driver"=>"sftp","host"=>"ff-sftp","port"=>22,"username"=>"ff","password"=>"ffpass","root"=>"upload"],true);$s=preg_replace("/\x27disks\x27 => \[\n/","\x27disks\x27 => [\n\x27sftp\x27 => $e,\n",$s,1);file_put_contents($f,$s);'
sleep 3

rc=0
echo "── Laravel, operator disk"; docker exec "$L" php /tmp/check.php "${LA[@]}" sftp op FLUXFILES_SECRET || rc=1
echo "── Laravel, BYOB disk"; docker exec "$L" php /tmp/check.php "${LA[@]}" sftp-byob byob FLUXFILES_SECRET || rc=1
echo "── WordPress, BYOB disk"; docker exec "$W" php /tmp/check.php "${WA[@]}" sftp-byob byob FF_E2E_SECRET || rc=1
exit $rc
