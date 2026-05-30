# FluxFiles — Test Plan (full case)

> Updated: 2026-05-30. Tracks the real code in `packages/core/api/`. Markers: ✅ covered, ⬜ to add.

## 0. Test layers & how to run

| Layer | Tooling | Command |
|---|---|---|
| Core unit/integration | PHP CLI | `php packages/core/tests/unit/*.php` + `tests/integration/*.php` |
| Core API e2e | bash + server | `php -S localhost:8080 router.php` + `bash tests/e2e/test-api.sh` |
| Visibility/URL | PHP CLI | `php tests/unit/test-visibility.php` ✅ |
| Image processing | PHP CLI | `php tests/integration/test-images.php` ✅ |
| PHP version matrix | Docker | images `fluxfiles-php81/82/83/84` |
| React/Vue wrappers | vitest + jsdom | `cd tests/apps && npm test` ✅ |
| Laravel | PHPUnit/Orchestra | `tests/apps/laravel-host/run-test.php` ⬜ |
| WordPress | stubbed WP | `php packages/wordpress/tests/test-wp-smoke.php` ✅ |
| Browser e2e (iframe/SDK) | Playwright MCP | postMessage flow ⬜ |

---

## 1. UPLOAD matrix by file type

Variant thresholds (`ImageOptimizer`): `thumb=150, medium=768, large=1920` WebP. Rule: `width ≤ maxWidth && name≠thumb → skip`.

### 1a. Images → generate variants (ext: jpg, jpeg, png, gif, webp, bmp) ✅ `test-images.php`
| Case | Input | Expected |
|---|---|---|
| Image ≤150px | png 100×80 | `thumb` only (no upsize) |
| 768<w≤1920 | png 1000×600 | `thumb` + `medium` (large skipped) |
| >1920 | jpg 2400×1400 | `thumb`+`medium`+`large` |
| Each ext | gif/webp/bmp/jpeg | WebP variant generated, readable back |
| Corrupt/0-byte | bad.jpg | upload **still 200**, optimizer error caught + logged, `variants:null` |

### 1b. Non-image files → no variants ✅
pdf, txt, mp4, mp3, webm, docx, xlsx, csv, json, **svg** (not in IMAGE_EXTENSIONS), zip, tar.gz → `variants:null`, 200.

### 1c. Blocked / dangerous extensions ✅
| Case | Expected |
|---|---|
| Dangerous ext (`php/phtml/exe/sh/bat/jsp/htaccess`...) | 403 `ext_dangerous` (regardless of allowedExt) |
| Double-extension (`shell.php.jpg`, `evil.phtml.png`) | 403 `ext_dangerous` (scans every part) |
| Ext outside allowedExt | 403 `ext_not_allowed` |
| allowedExt=null | every non-dangerous ext passes |
| No ext (`README`) | passes when allowedExt=null |
| Upper/lower (`IMG.JPG`) | normalized to lowercase |

### 1d. Size / quota ✅ `test-quota.php`
size>max_upload → 413 `upload_too_large`; size=max → passes; total over max_storage → 413 `quota_exceeded` (when `max_storage>0`); max_storage=0 → unlimited.

---

## 2. "ALREADY EXISTS vs NOT" scenarios

### 2a. SHA-256 dedup ✅ basics, ⬜ extended
| Case | Expected |
|---|---|
| New file | 200, hash saved |
| Same hash, `force_upload=false` | 200 `duplicate:true`, returns existing key + variants, no overwrite ✅ |
| Same hash, `force_upload=true` | 200, writes normally ✅ |
| Same hash but file deleted (stale index) | NOT reported as dup, purge entry, upload proceeds ⬜ |
| Same hash inside `_fluxfiles/`/`_variants/` | skipped ⬜ |
| Dedup + owner_only | only matches the user's own file ⬜ |
| Dedup + pathPrefix | only matches within the prefix ⬜ |

### 2b. Same NAME, different content ✅
Uploading `a.jpg` (different content) over an existing `a.jpg` → **overwrite** (upload has no collision guard) → variants regenerated; metadata/hash updated to the new file.

### 2c. Folder exists / not ✅ (mkdir/upload tested)
mkdir new → 200; mkdir existing → idempotent; upload into a non-existent folder → parent auto-created.

### 2d. Variant already exists ✅
Uploading an image that already has variants → `process()` overwrites them. Crop over an image with variants → regenerates.

---

## 2bis. PRE-EXISTING FILES/IMAGES (placed directly on storage, NOT via FluxFiles) ✅ **covered (local + S3/R2)**

> Scenario: files/images already on disk/bucket (manual copy, `aws s3 cp`, migration) — **no** `.meta.json` sidecar, **no** `_fluxfiles/index.json` entry, **no** hash, **no** `_variants`. `ExistingFileIndexer` is **not auto-wired** to any route, so files default to **State A (un-indexed)**.
> ✅ **Covered**: `integration/test-existing-files.php` (19 cases — State A + B + idempotency + State C pagination) + `e2e/test-s3-live.php` pre-existing branch (raw PUT: list/meta/metadata-null/presign/dedup-miss/index+variants — verified MinIO+AWS+R2) + audit (`test-audit.php`).

### A. State A (un-indexed) — every operation must work gracefully
| Operation | Expected for an un-indexed pre-existing file |
|---|---|
| `list` | file shows up (real on disk); manually placed `_fluxfiles`/`_variants`/`.meta.json` → **still hidden** |
| `meta` (`/api/fm/meta`) | size/mime/modified correct; `variants:null`; no sidecar needed |
| `metadata` GET | returns null/empty (no index entry) — **no error** |
| `metadata` PUT | creates a sidecar/index for the pre-existing file |
| `search` | **NOT** found (not in FTS/index) until indexed |
| **dedup** when uploading content matching a pre-existing file | **NOT detected** (no hash) → uploads as a new file ⚠️ (documented behaviour) |
| `rename`/`move` | OK; no variant/metadata to carry; folder index updated |
| `copy` | OK; no variant/metadata copy (none exist) |
| `delete` | OK; no error on missing sidecar/variant |
| `crop` on a pre-existing image | reads file → produces output OK (no index needed) |
| `ai-tag` on a pre-existing image | reads file → tags OK |
| `owner_only` | file with no `uploaded_by` → legacy **allowed** ✅ |
| `presign` (S3) on a pre-existing object | presign GET OK (no index needed) ✅ |

### B. After `ExistingFileIndexer.index()` — option matrix ✅
| Option | Expected |
|---|---|
| default | creates index + metadata (`title`=filename), counts `files_indexed`/`folders_indexed` |
| `hash:true` | computes + stores `file_hash` → **dedup works** for those files afterwards |
| `variants:true` | generates `_variants` for pre-existing images → `list`/`meta` then show variants |
| `owner:'u'` | sets `uploaded_by` → `owner_only` starts enforcing |
| `readonly:true` | marks owner `__fluxfiles_readonly__` |
| `overwrite:false` (default) | **idempotent**: re-run → `skipped` = already indexed, no duplicates |
| `dry_run:true` | counts but **writes nothing** |
| `path:'sub/'` | indexes only that subtree |
| skip rule | skips `_fluxfiles`/`_variants`/`*.meta.json` |
| after index | `search` finds it, `dedup` works, variants present |

> Note: on S3/R2 a raw-PUT object returns a non-null (empty) HeadObject metadata array, so the default indexer skips it as "already indexed" — use `overwrite:true` to (re)index such objects.

### C. Cross-cutting ⬜
- ✅ **S3/R2**: raw-PUT objects → list/index/meta/presign/variant-gen.
- ✅ **Large tree** → pagination (`list?limit>0&cursor`, `test-existing-files.php` State C).
- ⬜ Pre-existing name clashing with a system folder.
- ✅ Audit log on operations (`test-audit.php`).
- ⬜ Re-index after some files are already FluxFiles-created (mixed indexed + un-indexed).

---

## 3. RENAME / MOVE / COPY — collision (unlike upload: guarded) ✅
| Op | Free destination | Existing destination (file/folder) |
|---|---|---|
| rename/move/copy | 200 + carries `_variants/*` + metadata | 409 `name_exists` (`assertTargetAvailable`) ✅ |
| cross-copy/move (local→local, →s3) | 200 stream + variant+metadata transfer | ⬜ verify overwrite behaviour |

Deleting an image removes its variants and prunes an empty `_variants` dir. ✅ `test-delete-folder.php`

---

## 4. Security / Claims ✅ basics, ⬜ extended
perms (read/write/delete → correct 403); disks (outside claim → 403); prefix scope (`../`, null byte, `..%2f` sanitized); path traversal + `_fluxfiles/`/`_variants/` (`assertNotSystem`); owner_only (other user → 403, legacy no-owner → pass; **folder-level**: delete/rename/move a folder containing others' files → 403 via `assertOwnsTree`); BYOB (local BYOB rejected; SSRF endpoints blocked; mixed local+r2); JWT (missing/invalid/expired → 401; **secret <32 bytes → jwt v7 error**); CORS/Origin (forged cross-origin → 403, same-origin fallback); rate limit (60 read/10 write → 429).

---

## 5. Visibility S3/R2 — public vs private (URL logic ✅ `test-visibility.php`)

> Fixed: per-disk `visibility` + `public_url` config (`config/disks.php`, env `AWS_VISIBILITY/AWS_PUBLIC_URL/R2_VISIBILITY/R2_PUBLIC_URL`).

| Case | Expected | Status |
|---|---|---|
| local | base URL `config['url']` | ✅ |
| s3 `private` (default) | presigned GET (`X-Amz-Signature`) | ✅ |
| s3 `public` | direct bucket URL, no signature; writes use public-read ACL | ✅ |
| s3 `public` + `public_url` | CDN base | ✅ |
| r2 `private` | presigned GET | ✅ |
| r2 `public` + `public_url` | custom domain | ✅ |
| r2 `public` without `public_url` | fallback to endpoint/bucket | ✅ |
| **Live private bucket** presigned GET → 200; raw URL → 403 | `test-s3-live.php` | ✅ MinIO + AWS + R2 |
| **Presign TTL** (`url_ttl`, default 3600, clamp 86400) | URL expires correctly | ⬜ |
| **Live public bucket** raw URL → 200 | needs public bucket policy | ⬜ |
| **Variant URL by visibility** | private → presigned, public → direct | ⬜ |
| R2 `public` sends ACL? | NO ACL call (R2 has none) | ✅ |

### Real bugs found via live testing (fixed)
1. **AWS "Bucket owner enforced" (ACLs disabled — AWS default since 2023-04)** → every write failed `AccessControlListNotSupported`. Fix: middleware strips the `ACL` param on every disk except native-AWS-public (`DiskManager`).
2. **R2 returns no ContentType on HeadObject** → `fileMeta()` (`/api/fm/meta`) threw 500. Fix: `fileMeta()` is tolerant + falls back to an extension-based mime guess.
3. **move/copy previously had NO collision guard → silent overwrite** (rename did). Fixed: shared `assertTargetAvailable()` for rename/move/copy → all return 409 `name_exists` when the destination exists. Tested in `test-images.php`.

---

## 6. Storage backends
- **Local**: full matrix (sidecar `.meta.json`, `_fluxfiles/index.json`, `dirs.json`, `audit.jsonl`). ✅ mostly
- **S3/R2 live** ✅ via `test-s3-live.php` (env-gated, runs against MinIO/AWS/R2): upload+variants, list, fileMeta, presigned GET 200, raw-private 403, presign PUT→GET readback, delete, pre-existing branch. CI job `s3-minio`.
- **Chunk multipart** (init/presign/complete/abort) ✅ `test-s3-live.php` (verified MinIO+AWS+R2).

---

## 7. Wrapper packages (postMessage)
- ✅ **SDK**: FM_READY→FM_CONFIG, FM_SELECT→onSelect+close, token refresh (UPDATED/FAILED/no-handler/concurrent-dedup), foreign-origin ignored (`apps/__tests__/sdk.test.mjs`).
- ✅ **React/Vue**: `<FluxFiles>` iframe render, FM_CONFIG, FM_SELECT→onSelect/emit, FM_TOKEN_REFRESH, origin guard (`react.test.tsx`, `vue.test.ts`).
- ⬜ **CKEditor4/TinyMCE**: insert the chosen image URL into the editor.
- ⬜ **Laravel**: token + BYOB/mixed token, controller proxy assets, Blade component, auth→JWT.
- ✅ **WordPress**: token/byob-token/disk-config via stubbed WP (`wordpress/tests/test-wp-smoke.php`); ⬜ REST `/wp-json/fluxfiles/v1/`, shortcode, media-button modal.
- ⬜ **Browser e2e (Playwright)**: pick existing image → `FM_SELECT` URL+variants; upload → thumbnail; inline crop; multi-select bulk; dark mode.

---

## 8. PHP version matrix (Docker)
Run unit/integration + `test-api.sh` on **8.1, 8.2, 8.3, 8.4** (CI `core-php` + `api-e2e`). Confirms jwt v7 + flysystem v3 + intervention v3 resolve cleanly, variants correct, `composer audit` clean.

---

## 9. Edge / worst-case ⬜
unicode/emoji/spaces/>255-char names/`#?&`; huge files → chunk; mid-stream abort; concurrent same-name uploads (race, last-wins); quota boundary; FTS5 search with diacritics/special chars; CMYK/exif-rotated/animated-gif images; i18n 16 languages + RTL (ar) + missing-key fallback.

---

## 10. Core features — mostly covered (review 2026-05-30)
- ✅ **AI auto-tag**: response parsing (fence/truncate/filter/invalid→502) + manual `aiTag` + auto-tag-on-upload (stub tagger) — `test-aitagger.php` (12).
- ✅ **Chunk upload**: init→presign part→PUT→complete→readable + init→abort→complete-fails — `test-s3-live.php` (MinIO+AWS+R2).
- ✅ **Audit log**: log/list round-trip, per-user filter, limit/offset, rotation (`test-audit.php`).
- ✅ **Pagination**: `list?limit>0` returns `{items,next_cursor,total}`; cursor walks the whole tree with no gaps/dupes (`test-existing-files.php` State C).
- ✅ **Recursive folder delete**: children + `_variants` (all depths) + metadata + folder index, + edges (empty/nested/mixed/404/403/system/owner_only) — `test-delete-folder.php` (14).
- ✅ **Quota recalc**: getUsage/getQuotaInfo + assertQuota 413 + upload-blocked-then-allowed-after-delete — `test-quota.php` (5).
- ✅ **Token refresh** (SDK + React + Vue): REFRESH→onTokenRefresh→UPDATED/FAILED/no-handler + concurrent de-dup + origin guard — vitest.
- ✅ **Crop edge**: save-as 409 collision (guard added), in-place overwrite, format from source, out-of-bounds, write-perm — `test-crop.php` (6).

---

## Status & remaining priorities (2026-05-30)

**Done:** full upload/variant matrix, pre-existing files (local + S3/R2), rename/move/copy collision, recursive folder delete, quota, crop, visibility, S3/R2 live (MinIO+AWS+R2), chunk upload, AI auto-tag, audit, pagination, token refresh, security (XSS/SSRF/CSRF + owner_only folder gate), JS wrappers (SDK/React/Vue), WordPress smoke, PHP 8.1–8.4 + MinIO CI.

**Remaining ⬜:**
1. Dedup edge cases (stale index, system-path, owner_only/prefix scoping).
2. CKEditor4/TinyMCE + Laravel wrapper tests; WP REST/shortcode/media-button.
3. Browser e2e via Playwright; presign-TTL + live-public-bucket + variant-URL-by-visibility.
4. Edge/worst-case (section 9).
