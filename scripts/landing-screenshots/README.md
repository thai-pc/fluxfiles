# Landing-page screenshots

`capture.mjs` produces the `fluxfiles-demo-*.png` product shots for the marketing
site (1280×820, light theme, English, standalone `/public/` UI, feature dialogs
open over the dimmed app). Re-run it whenever the UI changes.

| Shot | What it shows | Needs |
|---|---|---|
| `versioning` | Version history of a contract, 4 prior versions + Restore | `versioning` module |
| `ocr` | OCR dialog with text extracted from a scanned invoice | `ocr` module + `tesseract` |
| `virus-scan` | On-demand scan, "No threats found" | `virus` module (ClamAV, or the stand-in, see below) |
| `backup` | Backup of `/Marketing` to a second disk, with the result summary | `backup` module |
| `audit-export` | Activity panel: Export NDJSON/CSV, Integrity check, Verify log ("Log intact") | `audit-export` module |
| `retention` | Retention dialog with a dry-run preview (one file skipped by a legal hold) | `legal-hold` module |
| `duplicates` | Usage dashboard duplicate finder with three groups | free core |
| `posters` | Grid with PDF and video poster thumbnails | free core + `gs` + `ffmpeg` |
| `share-recipient` | The branded public share page a recipient opens (image preview, Download) | `share` module |
| `intake-portal` | The branded upload portal after a client dropped three files | `intake` module |
| `intake-request` | A document request: checklist, message, due date, 2 of 3 required received | `intake` module (≥ 1.7.0) |
| `notice-email` | The owner's "new files" digest for a drop, sent for real through SMTP | `intake` module + an SMTP catcher (`--smtp`, `--mailpit`) |

The four recipient-side shots brand their pages as the fictional "Northwind Studio"
(`share_brand_*` / `intake_brand_*` claims); the logo URL is on `northwind.example`
and Playwright answers it locally, so nothing leaves the machine. `notice-email` is
a plain mail-reader card filled verbatim from the message Mailpit caught (From, To,
Subject, Date, text body): the notices are plain text, and the script writes none
of their content.

## Run

```bash
# once: Playwright from the browser suite
(cd packages/core/tests/browser && npm ci && npx playwright install chromium)

node scripts/landing-screenshots/capture.mjs                     # all shots → scripts/landing-screenshots/out/
node scripts/landing-screenshots/capture.mjs --only=ocr,backup   # a subset
node scripts/landing-screenshots/capture.mjs --copy-to=../fluxfiles-landing/public/demo
```

Options: `--only=a,b` · `--copy-to=DIR` (copies each successful shot) · `--port=N`
(default 8140) · `--keep` (keep the temp work dir for debugging) ·
`--modules-root=DIR` (where the private modules live, default `packages/`; a git
worktree has none) · `--module-paths=intake=DIR,share=DIR` (a specific checkout of
a module, e.g. a worktree at its release tag) · `--smtp=HOST:PORT --mailpit=URL`
(the notice e-mail shot; skipped without them).

```bash
# notice-email: a local SMTP catcher (under Colima, Mailpit needs MP_SMTP_DISABLE_RDNS=true)
docker run -d --name ff-shots-mailpit -e MP_SMTP_DISABLE_RDNS=true -p 127.0.0.1:1025:1025 -p 127.0.0.1:8025:8025 axllent/mailpit
node scripts/landing-screenshots/capture.mjs --only=notice-email --smtp=127.0.0.1:1025 --mailpit=http://127.0.0.1:8025
docker rm -f ff-shots-mailpit
```

### WordPress.org plugin page (`--set=wporg`)

`--set=wporg` writes the free plugin's `.wordpress-org/screenshot-1..3.png`
(1280×800) instead: no paid module installed, no licence, `pro_hints=false`.
1 = grid at the root, 2 = crop editor in the details panel, 3 = a throttled
four-file upload mid-way. `--only=dark` adds a dark-mode alternative (`dark.png`).
Real extra disks for the disk switcher (optional, both on loopback, allow-listed
through `FLUXFILES_SSRF_ALLOW_HOSTS` on the throwaway server):

```bash
docker run -d --name ff-wporg-sftp -p 127.0.0.1:2222:22 atmoz/sftp ff:ffpass:::upload
docker run -d --name ff-wporg-s3 -p 127.0.0.1:4566:4566 -e SERVICES=s3 localstack/localstack:4
curl -X PUT http://127.0.0.1:4566/website-assets           # the bucket the s3 disk uses
node scripts/landing-screenshots/capture.mjs --set=wporg --s3-endpoint=http://127.0.0.1:4566 --sftp-port=2222
docker rm -f ff-wporg-sftp ff-wporg-s3
```

Screenshot 4 (the block editor) comes from `wporg-editor.mjs`, which drives the
real plugin in the WordPress e2e's wp-env site:

```bash
bash packages/core/tests/apps/wordpress-e2e/setup.sh        # Docker
node scripts/landing-screenshots/wporg-editor.mjs           # → .wordpress-org/screenshot-4.png
(cd packages/core/tests/apps/wordpress-e2e && npx @wordpress/env stop)
```

Look at every PNG before you publish it. A shot that fails is saved as
`out/FAILED-<name>.png` and the script exits non-zero.

## What it does

1. Copies `packages/core` (api, assets, public, config, lang, db, vendor; the
   vendor copy is clone-on-write on APFS) into a temp dir. The copy has its own
   `storage/`, no `.env` and a throwaway `FLUXFILES_SECRET`, so your real `.env`,
   uploads and rate-limit state are never read or touched. Its `config/disks.php`
   adds a second local disk, `archive`, used by the Backup shot.
2. Installs every private module found in `packages/<id>/` (or `--modules-root` /
   `--module-paths`) as `vendor/fluxfiles/<id>` (symlinks), the layout
   `modules-autoload.php` resolves for a real install, and licenses them with a
   THROWAWAY key: `lib/license.php` generates a fresh Ed25519 keypair, rewrites the
   copy's `api/LicenseManager.php` so its `k1` public key is the throwaway one, and
   signs a 365-day licence with `scripts/license-gen.php`. The vendor signing key
   (`docs/license-signing-key.SECRET.txt`) is never read, and the licence is useless
   against any real install. It prints only the token.
3. Starts `php -S` on the copy and seeds demo content through the real HTTP API
   as four users: Marketing, Photos, Contracts, Invoices, Exports, Shared and Clients,
   with generated photos, cover PDFs, short MP4s (ImageMagick + ffmpeg) and an
   OCR-able invoice scan. Afterwards it backdates a few things, touching only the
   copy's storage: invoice and export mtimes (for the retention preview) and the
   timestamps in the versions manifest (so versions spread over days, not
   seconds). The audit log is never edited, so "Verify log" checks a real chain.
4. Drives Chromium through the UI the same way the specs in
   `packages/core/tests/browser` do, then screenshots it.

**Virus engine.** With no ClamAV installed (`clamdscan`/`clamscan`), the script
puts a tiny `clamdscan` stand-in on the server's `PATH` that reports every file
clean. The virus module, the `/api/fm/virus/scan` route and the UI all run
unmodified, and the script's output says which engine it used.

Requirements: PHP (with sodium), Node ≥ 18, ImageMagick 7 (`magick`), a TTF font
(Arial on macOS, DejaVu on Linux), plus `tesseract`, `gs` and `ffmpeg` (libx264)
for the shots that need them.
