#!/bin/bash
#
# Self-test of scripts/check-wp-dist.php: the clean zip must pass, and a copy with ONE
# planted violation per rule category must fail (exit 1). Guards the guard against
# silently turning into a no-op.
#
#   bash scripts/check-wp-dist-selftest.sh [path/to/fluxfiles.zip]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ZIP="${1:-$ROOT/build/fluxfiles.zip}"
ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

php "$ROOT/scripts/check-wp-dist.php" "$ZIP" >/dev/null || { echo "FAIL: the clean zip does not pass the guard"; exit 1; }
echo "ok   clean zip passes"

fails=0
# <label> <relative file to append to> <payload>
plant() {
    local label="$1" file="$2" payload="$3"
    local dir="$WORK/$label"
    mkdir -p "$dir"
    (cd "$dir" && unzip -q "$ZIP")
    mkdir -p "$(dirname "$dir/fluxfiles/$file")"
    printf '%s\n' "$payload" >> "$dir/fluxfiles/$file"
    (cd "$dir" && zip -qr bad.zip fluxfiles)
    if php "$ROOT/scripts/check-wp-dist.php" "$dir/bad.zip" >/dev/null 2>&1; then
        echo "FAIL $label: the guard accepted the planted violation"; fails=$((fails + 1))
    else
        echo "ok   $label rejected"
    fi
}

plant self-update      includes/FluxFilesPlugin.php '// pre_set_site_transient_update_plugins'
plant wp-load          includes/FluxFilesPlugin.php '// require "wp-load.php";'
plant wp-load-concat   includes/FluxFilesPlugin.php '$x = "wp-" . "load.php";'
plant eval             includes/FluxFilesPlugin.php '<?php eval($x);'
plant assert-string    includes/FluxFilesPlugin.php '<?php assert("phpinfo()");'
plant base64-eval      includes/FluxFilesPlugin.php '<?php eval(base64_decode($x));'
plant curl             includes/FluxFilesPlugin.php '<?php curl_exec($ch);'
plant socket           includes/FluxFilesPlugin.php '<?php fsockopen("x", 80);'
plant script-cdn       templates/ui-index.php '<script src="https://cdn.example/x.js"></script>'
plant script-protocol  templates/ui-index.php '<script src="//cdn.example/x.js"></script>'
plant link-external    templates/ui-index.php '<link rel="stylesheet" href="//cdn.example/x.css">'
plant css-import       assets/fm.css '@import url("https://cdn.example/x.css");'
plant js-src           assets/fm.js "s.src = '//cdn.example/x.js';"
plant moved-function   api/FileManager.php '<?php function runRetention($a) { return 1; }'
plant paid-module      vendor/fluxfiles/share/x.php '<?php'
plant forbidden-path   tests/x.php '<?php'
plant helpers          api/helpers.php '<?php'
plant license-manager  api/Claims.php '<?php class LicenseManager {}'
plant raw-html         public/index.html '<html></html>'
plant raw-svg          includes/x.svg '<svg/>'
plant hidden-file      public/.htaccess 'Require all denied'
plant hidden-vendor    vendor/foo/.travis.yml 'x'
plant vendor-sh        vendor/foo/build.sh '#!/bin/sh'
plant vendor-bin       vendor/foo/bin/tool 'x'
plant vendor-phar      vendor/foo/tool.phar 'x'
plant root-markdown    CHANGES.md '# x'
plant public-link      public-link.php '<?php'

[ "$fails" -eq 0 ] || { echo "$fails planted violation(s) slipped through"; exit 1; }
echo "OK: every planted violation was rejected"
