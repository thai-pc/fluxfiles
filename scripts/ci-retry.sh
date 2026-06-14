#!/usr/bin/env bash
# Retry a (usually network-bound) command a few times — CI registries/CDNs
# occasionally reset the connection (ECONNRESET) mid-download, which is transient.
# Usage: bash scripts/ci-retry.sh <command> [args...]
set -uo pipefail

attempts="${RETRIES:-4}"
delay="${RETRY_DELAY:-15}"

for i in $(seq 1 "$attempts"); do
  if "$@"; then
    exit 0
  fi
  if [ "$i" -lt "$attempts" ]; then
    echo "::warning::attempt ${i}/${attempts} failed: $* — retrying in ${delay}s"
    sleep "$delay"
  fi
done

echo "::error::all ${attempts} attempts failed: $*"
exit 1
