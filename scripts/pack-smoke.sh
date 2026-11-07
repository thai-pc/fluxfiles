#!/usr/bin/env bash
# Pack each JS wrapper and install the tarball into a throwaway consumer, then
# typecheck/import it — verifies the PUBLISHED dist + types actually resolve
# (the per-package vitest suites import from src/, not the built package).
#
#   scripts/pack-smoke.sh [react|vue|sdk|ckeditor5|mcp]   # default: all
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

base_tsconfig() {
  cat > tsconfig.json <<'JSON'
{ "compilerOptions": { "noEmit": true, "skipLibCheck": true, "strict": false,
  "module": "esnext", "moduleResolution": "bundler", "jsx": "react-jsx",
  "esModuleInterop": true, "types": [] }, "include": ["*.ts", "*.tsx"] }
JSON
}

smoke_react() {
  echo "── react ──"
  ( cd "$ROOT/packages/react" && npm run build >/dev/null 2>&1 && npm pack --silent >/tmp/.tb )
  local tb; tb="$ROOT/packages/react/$(cat /tmp/.tb)"
  local C="$TMP/react"; mkdir -p "$C"; cd "$C"; npm init -y >/dev/null 2>&1
  npm install --silent --no-audit --no-fund "$tb" react react-dom typescript @types/react @types/react-dom >/dev/null 2>&1
  rm -f "$tb"
  printf "import { FluxFiles } from '@fluxfiles/react';\nexport const el = <FluxFiles endpoint=\"x\" token=\"t\" onSelect={() => {}} />;\n" > consume.tsx
  base_tsconfig
  npx --no-install tsc
  echo "  ✓ @fluxfiles/react published package installs + typechecks"
}

smoke_vue() {
  echo "── vue ──"
  ( cd "$ROOT/packages/vue" && npm run build >/dev/null 2>&1 && npm pack --silent >/tmp/.tb )
  local tb; tb="$ROOT/packages/vue/$(cat /tmp/.tb)"
  local C="$TMP/vue"; mkdir -p "$C"; cd "$C"; npm init -y >/dev/null 2>&1
  npm install --silent --no-audit --no-fund "$tb" vue typescript >/dev/null 2>&1
  rm -f "$tb"
  printf "import { FluxFiles } from '@fluxfiles/vue';\nconst c: unknown = FluxFiles;\nexport default c;\n" > consume.ts
  base_tsconfig
  npx --no-install tsc
  echo "  ✓ @fluxfiles/vue published package installs + typechecks"
}

smoke_sdk() {
  echo "── sdk ──"
  ( cd "$ROOT/packages/sdk" && npm pack --silent >/tmp/.tb )
  local tb; tb="$ROOT/packages/sdk/$(cat /tmp/.tb)"
  local C="$TMP/sdk"; mkdir -p "$C"; cd "$C"; npm init -y >/dev/null 2>&1
  npm install --silent --no-audit --no-fund "$tb" typescript >/dev/null 2>&1
  rm -f "$tb"
  printf "import FluxFiles from 'fluxfiles';\nFluxFiles.open({ endpoint: 'x', token: 't' });\n" > consume.ts
  base_tsconfig
  npx --no-install tsc
  node -e "const F=require('fluxfiles'); if(typeof F.open!=='function') throw new Error('open missing'); console.log('  ✓ require(fluxfiles).open works')"
  echo "  ✓ fluxfiles published package installs + typechecks"
}

smoke_ckeditor5() {
  echo "── ckeditor5 ──"
  ( cd "$ROOT/packages/ckeditor5" && npm run build >/dev/null 2>&1 && npm pack --silent >/tmp/.tb )
  local tb; tb="$ROOT/packages/ckeditor5/$(cat /tmp/.tb)"
  local C="$TMP/ckeditor5"; mkdir -p "$C"; cd "$C"; npm init -y >/dev/null 2>&1
  npm install --silent --no-audit --no-fund "$tb" ckeditor5 typescript >/dev/null 2>&1
  rm -f "$tb"
  printf "import { ClassicEditor } from 'ckeditor5';\nimport { FluxFiles } from '@fluxfiles/ckeditor5';\nexport const create = (el: HTMLElement) => ClassicEditor.create(el, { licenseKey: 'GPL', plugins: [FluxFiles], toolbar: ['fluxfiles'], fluxfiles: { endpoint: 'x', token: 't' } });\n" > consume.ts
  base_tsconfig
  npx --no-install tsc
  echo "  ✓ @fluxfiles/ckeditor5 published package installs + typechecks"
}

smoke_mcp() {
  echo "── mcp ──"
  ( cd "$ROOT/packages/mcp" && npm run build >/dev/null 2>&1 && npm pack --silent >/tmp/.tb )
  local tb; tb="$ROOT/packages/mcp/$(cat /tmp/.tb)"
  local C="$TMP/mcp"; mkdir -p "$C"; cd "$C"; npm init -y >/dev/null 2>&1
  npm pkg set type=module >/dev/null 2>&1
  npm install --silent --no-audit --no-fund "$tb" typescript >/dev/null 2>&1
  rm -f "$tb"
  printf "import { buildTools, FluxFilesClient } from '@fluxfiles/mcp';\nconst t = buildTools(new FluxFilesClient({ baseUrl: 'https://x.test', token: 't' }), { disk: 'local', allowWrite: false, allowDelete: false, maxTextChars: 10 });\nexport const n: string[] = t.map((x) => x.name);\n" > consume.ts
  base_tsconfig
  npx --no-install tsc
  node --input-type=module -e "const m = await import('@fluxfiles/mcp'); if (typeof m.createServer !== 'function') throw new Error('createServer missing'); console.log('  ✓ import(@fluxfiles/mcp).createServer works')"
  echo "  ✓ @fluxfiles/mcp published package installs + typechecks"
}

case "${1:-all}" in
  react) smoke_react ;;
  vue)   smoke_vue ;;
  sdk)   smoke_sdk ;;
  ckeditor5) smoke_ckeditor5 ;;
  mcp)   smoke_mcp ;;
  all)   smoke_sdk; smoke_react; smoke_vue; smoke_ckeditor5; smoke_mcp ;;
  *) echo "usage: $0 [react|vue|sdk|ckeditor5|mcp|all]"; exit 1 ;;
esac
echo "pack-smoke OK"
