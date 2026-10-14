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

## Run

```bash
# once: Playwright from the browser suite
(cd packages/core/tests/browser && npm ci && npx playwright install chromium)

node scripts/landing-screenshots/capture.mjs                     # all shots → scripts/landing-screenshots/out/
node scripts/landing-screenshots/capture.mjs --only=ocr,backup   # a subset
node scripts/landing-screenshots/capture.mjs --copy-to=../fluxfiles-landing/public/demo
```

Options: `--only=a,b` · `--copy-to=DIR` (copies each successful shot) · `--port=N`
(default 8140) · `--keep` (keep the temp work dir for debugging).

Look at every PNG before you publish it. A shot that fails is saved as
`out/FAILED-<name>.png` and the script exits non-zero.

## What it does

1. Copies `packages/core` (api, assets, public, config, lang, db, vendor; the
   vendor copy is clone-on-write on APFS) into a temp dir. The copy has its own
   `storage/`, no `.env` and a throwaway `FLUXFILES_SECRET`, so your real `.env`,
   uploads and rate-limit state are never read or touched. Its `config/disks.php`
   adds a second local disk, `archive`, used by the Backup shot.
2. Installs every private module found in `packages/<id>/` as
   `vendor/fluxfiles/<id>` (symlinks), the layout `modules-autoload.php` resolves
   for a real install, and licenses them with a 365-day local licence from
   `scripts/license-gen.php` + `docs/license-signing-key.SECRET.txt`, the same
   way `tests/e2e/test-sso-http.php` does (`lib/license.php` prints only the
   token). If the signing key isn't on this machine, the paid shots are skipped.
3. Starts `php -S` on the copy and seeds demo content through the real HTTP API
   as four users: Marketing, Photos, Contracts, Invoices, Exports and Shared,
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
