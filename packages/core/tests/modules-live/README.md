# Paid modules, live (Playwright + Docker)

`tests/browser` drives the standalone UI against a stock core, so every paid-module
response there is mocked. This rig runs the **real** modules instead: core with all
13 modules installed, a genuine license, and the engines they shell out to.

| Module | Engine in the stack |
|---|---|
| share, intake, versioning, legal-hold, audit-export | core storage (local disk) |
| webhooks | `ff-hooks` receiver (signature checked in the test) |
| ai | `ff-ai` — a self-hosted image-model stand-in (`provider=http`) |
| ocr | Tesseract 5 (in the core image) |
| virus | ClamAV `clamd` (in the core image; EICAR test string) |
| dlp | Microsoft Presidio analyzer (`ff-presidio`) |
| backup | S3 on LocalStack (second disk) |
| c2pa | c2patool + the c2pa-rs ES256 test certificate |
| sso | `ff-idp` — a real OIDC provider (PKCE, RS256 id_tokens) |

## SFTP through the adapters

`sftp/run.sh` builds the real Laravel and WordPress rigs from this tree, starts a real
`atmoz/sftp` server, and drives every SFTP route through each adapter's HTTP API,
checking results directly on the server (Laravel operator disk + BYOB, WordPress BYOB).
Needs `ff-modules-live:latest`; no Playwright. Containers are removed afterwards.

## Run

```bash
cd packages/core/tests/modules-live
npm install                    # once
npx playwright install chromium   # once
npx playwright test            # stage → build → up → test → down
FF_LIVE_KEEP=1 npx playwright test    # leave the stack running afterwards
FF_LIVE_REUSE=1 npx playwright test   # use an already-running stack (fast loop)
```

Needs: Docker, the 13 private module repos checked out under `packages/<id>/`, and
the vendor's local license-signing key (`docs/license-signing-key.SECRET.txt`) —
`stage.sh` mints a 30-day all-modules license with it and never prints the key or the
license. Without the key the run stops at staging, so this is a **local** suite, not CI.

`stage.sh` copies core + the module sources into `.stage/app` (never the dev `.env`,
never runtime storage) and writes `.stage/core.env`; the image is built from that copy.
Ports on the host: 8190 (core), 8191 (IdP), 8193 (webhook receiver). The browser
reaches the IdP as `http://ff-idp:8191` via a host-resolver rule, the same URL core
uses inside the compose network, so the issuer matches on both sides.

**Through the Laravel proxy:** `laravel/` runs the same modules and engines behind a
real Laravel app with `fluxfiles/laravel` in proxy mode — see `laravel/README.md`
(`npx playwright test -c laravel/playwright.config.ts`, ports 8290/8293).

## WordPress edition

`wordpress/` runs the same modules through the **WordPress plugin proxy** (the
`[fluxfiles]` shortcode, a licence pasted into the plugin option, claims via
`fluxfiles_token_overrides`) on its own compose project `ff-live-wp` (ports 8390/8393).
Its image builds on `ff-modules-live:latest`. See [`wordpress/README.md`](wordpress/README.md).
