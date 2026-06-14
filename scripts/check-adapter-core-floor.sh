#!/usr/bin/env bash
#
# Adapter ↔ core floor guard.
#
# The PHP adapters (laravel, wordpress) declare a `fluxfiles/fluxfiles` Composer
# constraint. Their smoke tests, however, normally load the *live* monorepo core
# (always newest), so a call to a core API newer than the declared floor passes
# in CI but 500s in a user's app (this is exactly the sanitizeVariants regression).
#
# This script builds core at each adapter's *declared floor version* (from a
# matching `core-vX.Y.Z` monorepo tag, in a throwaway git worktree) and runs the
# adapter smoke against THAT, via FLUXFILES_CORE_AUTOLOAD. If the adapter uses a
# core symbol that doesn't exist at the floor, the smoke fails here — no human
# bookkeeping, no relying on remembering to bump composer.json.
#
# Usage: scripts/check-adapter-core-floor.sh [laravel|wordpress ...]
#   (default: all adapters that require fluxfiles/fluxfiles)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

ADAPTERS=("$@")
if [ ${#ADAPTERS[@]} -eq 0 ]; then
  ADAPTERS=(laravel wordpress)
fi

# Map adapter -> its smoke test file.
smoke_for() {
  case "$1" in
    laravel)   echo "packages/laravel/tests/test-laravel-smoke.php" ;;
    wordpress) echo "packages/wordpress/tests/test-wp-smoke.php" ;;
    *) echo "" ;;
  esac
}

# Extract the floor version from a `^0.2.8` / `~0.2.8` / `>=0.2.8` style constraint.
floor_from_constraint() {
  # grep the constraint string, then the first X.Y.Z in it.
  printf '%s' "$1" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1
}

fail=0
WORKTREES=()
cleanup() {
  for wt in "${WORKTREES[@]:-}"; do
    [ -n "$wt" ] && git worktree remove --force "$wt" 2>/dev/null || true
  done
}
trap cleanup EXIT

for adapter in "${ADAPTERS[@]}"; do
  composer_json="packages/${adapter}/composer.json"
  smoke="$(smoke_for "$adapter")"
  if [ ! -f "$composer_json" ] || [ ! -f "$smoke" ]; then
    echo "::warning::skip ${adapter}: missing composer.json or smoke test"; continue
  fi

  constraint="$(grep -E '"fluxfiles/fluxfiles"' "$composer_json" | head -1)"
  floor="$(floor_from_constraint "$constraint")"
  if [ -z "$floor" ]; then
    echo "::warning::skip ${adapter}: no pinned X.Y.Z floor in '${constraint}'"; continue
  fi

  tag="core-v${floor}"
  if ! git rev-parse -q --verify "refs/tags/${tag}" >/dev/null; then
    echo "::warning::skip ${adapter}: monorepo tag ${tag} not found (floor ${floor} not yet released?)"
    continue
  fi

  echo ">>> ${adapter}: smoke against core floor ${floor} (${tag})"
  wt="$(mktemp -d)/core-floor-${adapter}"
  git worktree add --quiet --detach "$wt" "$tag"
  WORKTREES+=("$wt")

  echo "    composer install -d ${wt}/packages/core"
  composer install --no-interaction --no-progress -q -d "$wt/packages/core"

  if FLUXFILES_CORE_AUTOLOAD="$wt/packages/core/vendor/autoload.php" php "$smoke"; then
    echo "    OK: ${adapter} works against core ${floor}"
  else
    echo "::error::${adapter} smoke FAILED against its declared core floor ${floor}."
    echo "::error::Either bump packages/${adapter}/composer.json fluxfiles/fluxfiles to the core version that added the API it now uses, or decouple from it."
    fail=1
  fi
done

exit $fail
