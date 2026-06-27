# Editor plugins — live-browser tests

Playwright tests that load the **real** editor libraries (TinyMCE, CKEditor 4,
Summernote + jQuery — vendored via npm into a gitignored `node_modules/`) plus each
editor's **shipped `plugin.min.js`**, and verify the watermark handling end to end:

- a **burn-in** image (the file has a normal `url`) → inserted as an `<img>`;
- a **preview-only** image (overlay watermark, `allow_download=false` → no `url`,
  only the short-lived `img_base`) → **not inserted**, and the plugin warns.

Only the picker is stubbed: `window.FluxFiles.open()` feeds a test-controlled
payload to `onSelect`, so the *real* editor + *real* plugin run in a *real*
browser. (The per-plugin unit tests in `packages/{tinymce,ckeditor4,summernote}`
still run on every change; this rig is the heavier "for real" cross-check.)

## Run

```bash
cd packages/core/tests/editors
npm run setup      # npm install (--ignore-scripts) + playwright install chromium
npm test
```

Notes:
- `npm install` uses `--ignore-scripts` (a transitive `husky` prepare script fails
  otherwise; we only need the static lib files).
- CKEditor 4 is pinned to **4.22.1** — the last GPL release; 4.23+ is LTS/commercial
  and refuses to initialise without a license key.
- The web server is `php -S` serving the repo root, so a harness page can load both
  the vendored libs and `packages/<editor>/plugin.min.js` from one origin.
