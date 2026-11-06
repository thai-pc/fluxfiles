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

## Real-picker specs (`real-picker.spec.ts`)

The specs above stub the picker. `real-picker.spec.ts` stubs **nothing**: a live core
(`php -S 127.0.0.1:8201 router.php`) serves the actual picker iframe, and for each of
**CKEditor 5, CKEditor 4, TinyMCE and Summernote** the test clicks the editor's toolbar
button, double-clicks a seeded file in the iframe and asserts the editor content:

- an image file becomes an `<img>` with `src` = the file's URL and `alt` = its name;
- a text file becomes a link (`href` = the file's URL, text = its name);
- CKEditor 5 also covers the image-insert dropdown entry (see the known bug below).

How it works:
- `global-setup.ts` writes a throwaway `FLUXFILES_SECRET` into `packages/core/.env`
  (existing one backed up, restored by `global-teardown.ts`); tokens are minted with
  `fluxfiles_token()` using the same secret. The specs seed/remove their own files in
  `packages/core/storage/uploads`. Needs core's composer deps (`composer install -d packages/core`) and `php`.
- Legacy editors: pages in `real/` load the vendored libs and the shipped `plugin.min.js`
  from the static server (8197).
- CKEditor 5: `real/ck5/` is a Vite host (port 8203) bundling the adapter **source**
  (`packages/ckeditor5/src`) with the real `ckeditor5` package and `licenseKey: 'GPL'`
  (CKEditor 5's open-source mode; no commercial key). The SDK comes from the bundled
  `fluxfiles` import (no page-level `<script>`), i.e. the npm-consumer path.
- Ports: 8197 static, 8201 core, 8203 Vite (override with `FF_EDITORS_PORT`,
  `FF_EDITORS_CORE_PORT`, `FF_EDITORS_CK5_PORT`).

Known adapter bug, tracked as a `test.fail()` in the spec: the CKEditor 5 adapter
registers its "FluxFiles" image-insert integration, but CKEditor only renders
integrations named in `image.insert.integrations` (default `upload, assetManager, url`),
so the dropdown entry is missing with the default config. The spec proves the entry works once listed.

CI: the `editor-e2e` job in `.github/workflows/test.yml` runs everything here.

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
