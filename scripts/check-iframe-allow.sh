#!/usr/bin/env bash
#
# iframe `allow` parity guard.
#
# Every surface that embeds the FluxFiles UI in an <iframe> must grant the SAME
# Permissions-Policy `allow` value (so the in-app fullscreen button + clipboard
# work consistently) plus the legacy allowfullscreen attribute. They live in
# four independently-published packages (SDK, React, Vue, WordPress) in two
# languages, so it's easy to change one and forget the others — this guard fails
# CI when they drift apart.
#
# Each package defines the value ONCE (a named constant); the components reference
# it. This script asserts that single source equals the canon, and that each
# package wires allowfullscreen.
#
# Usage: scripts/check-iframe-allow.sh

set -euo pipefail
cd "$(dirname "$0")/.."

CANON='clipboard-write; fullscreen'

# package label  →  the file holding its single IFRAME_ALLOW definition
sources=(
  "sdk:packages/sdk/fluxfiles.js"
  "wordpress:packages/wordpress/assets/fluxfiles.js"
  "react:packages/react/src/iframe.ts"
  "vue:packages/vue/src/iframe.ts"
)

# files that must wire the legacy allowfullscreen attribute (one per surface)
fullscreen_surfaces=(
  packages/sdk/fluxfiles.js
  packages/wordpress/assets/fluxfiles.js
  packages/react/src/FluxFiles.tsx
  packages/react/src/FluxFilesModal.tsx
  packages/vue/src/FluxFiles.vue
  packages/vue/src/FluxFilesModal.vue
)

fail=0

echo ">>> iframe allow parity — canon: '${CANON}'"

for entry in "${sources[@]}"; do
  label="${entry%%:*}"; file="${entry#*:}"
  if [ ! -f "$file" ]; then
    echo "::error::${label}: missing ${file}"; fail=1; continue
  fi
  # The canon must appear (the single source of truth) …
  if ! grep -qF "$CANON" "$file"; then
    echo "::error::${label}: ${file} does not define IFRAME_ALLOW = '${CANON}'"; fail=1
  fi
  # … and no OTHER clipboard-/fullscreen-ish allow value may sneak in.
  bad="$(grep -oE "'clipboard-write[^']*'|\"clipboard-write[^\"]*\"" "$file" | tr -d "'\"" | grep -vxF "$CANON" || true)"
  if [ -n "$bad" ]; then
    echo "::error::${label}: ${file} has a divergent allow value: ${bad}"; fail=1
  fi
done

for file in "${fullscreen_surfaces[@]}"; do
  if [ ! -f "$file" ]; then
    echo "::error::missing ${file}"; fail=1; continue
  fi
  if ! grep -qiE "allowfullscreen|allowFullScreen" "$file"; then
    echo "::error::${file}: missing the allowfullscreen attribute"; fail=1
  fi
done

if [ "$fail" -eq 0 ]; then
  echo "OK — all ${#sources[@]} packages grant '${CANON}' + allowfullscreen across ${#fullscreen_surfaces[@]} surfaces."
fi
exit "$fail"
