# FluxFiles — Test Plan (full case)

> Cập nhật: 2026-05-30. Bám sát code thật trong `packages/core/api/`. Đánh dấu: ✅ đã cover, ⬜ cần thêm.

## 0. Các lớp test & cách chạy

| Lớp | Công cụ | Lệnh |
|---|---|---|
| Core unit/integration | PHP CLI | `php packages/core/tests/test-*.php` |
| Core API e2e | bash + server | `php -S localhost:8080 router.php` + `bash tests/test-api.sh` |
| Visibility/URL | PHP CLI | `php tests/test-visibility.php` ✅ |
| Image processing | PHP CLI | ⬜ `test-images.php` (cần thêm) |
| PHP version matrix | Docker | image `fluxfiles-php81/82/83/84` |
| Wrappers React/Vue | vitest + jsdom | `tests/apps/` (scaffold có, ⬜ test thật) |
| Laravel | PHPUnit/Orchestra | `tests/apps/laravel-host/run-test.php` |
| WordPress | wp-now/wp-env | ⬜ chưa có |
| Browser e2e (iframe/SDK) | Playwright MCP | postMessage flow |

---

## 1. Ma trận UPLOAD theo dạng file

Ngưỡng variant (`ImageOptimizer`): `thumb=150, medium=768, large=1920` WebP. Luật: `width ≤ maxWidth && name≠thumb → skip`.

### 1a. Ảnh → sinh variant (ext: jpg, jpeg, png, gif, webp, bmp) ⬜
| Case | Input | Mong đợi |
|---|---|---|
| Ảnh ≤150px | png 100×80 | chỉ `thumb` (không upsize) |
| 150<w≤768 | jpg 500×300 | `thumb` + `medium` |
| 768<w≤1920 | png 1000×600 | `thumb` + `medium` (large skip) |
| >1920 | jpg 3000×2000 | đủ `thumb`+`medium`+`large` |
| Mỗi ext | gif/webp/bmp/jpeg | sinh variant WebP, đọc lại được |
| Ảnh corrupt/0 byte | rác.jpg | upload **vẫn 200**, variant fail được catch + log, `variants:null` |

### 1b. File không phải ảnh → không variant ⬜
pdf, txt, mp4, mp3, webm, docx, xlsx, csv, json, **svg** (không nằm trong IMAGE_EXTENSIONS), zip, tar.gz → `variants:null`, 200.

### 1c. Extension chặn / nguy hiểm (một phần ✅)
| Case | Mong đợi |
|---|---|
| Dangerous ext (`php/phtml/exe/sh/bat/jsp/htaccess`...) | 403 `ext_dangerous` (bất kể allowedExt) |
| Double-extension (`shell.php.jpg`, `evil.phtml.png`) | 403 `ext_dangerous` (quét mọi phần) ⬜ |
| Ext ngoài allowedExt | 403 `ext_not_allowed` |
| allowedExt=null | mọi ext không nguy hiểm qua |
| No-ext (`README`) | qua nếu allowedExt=null |
| Hoa/thường (`IMG.JPG`) | normalize lowercase |

### 1d. Kích thước / quota (một phần ✅)
size>max_upload → 413 `upload_too_large`; size=max → qua; tổng vượt max_storage → quota 4xx (khi `max_storage>0`); max_storage=0 → bỏ qua.

---

## 2. Kịch bản "ĐÃ CÓ SẴN vs CHƯA CÓ" ⬜ (gap chính)

### 2a. Dedup SHA-256 (cơ bản ✅, mở rộng ⬜)
| Case | Mong đợi |
|---|---|
| File mới | 200, lưu hash |
| Trùng hash, `force_upload=false` | 200 `duplicate:true`, trả key cũ + variants cũ, không ghi đè ✅ |
| Trùng hash, `force_upload=true` | 200, ghi bình thường ✅ |
| Trùng hash nhưng file đã bị xoá (stale index) | KHÔNG báo dup, purge entry, upload tiếp ⬜ |
| Trùng hash trong `_fluxfiles/`/`_variants/` | bỏ qua ⬜ |
| Dedup + owner_only | chỉ match file của chính user ⬜ |
| Dedup + pathPrefix | chỉ match trong prefix ⬜ |

### 2b. Trùng TÊN khác nội dung ⬜
Upload `a.jpg` (khác nội dung) lên `a.jpg` đã có → **upload GHI ĐÈ** (upload không có guard collision) → variant regenerate đè; metadata/hash cập nhật theo file mới.

### 2c. Folder đã có / chưa ⬜
mkdir chưa có → 200; mkdir đã có → idempotent; upload vào path chưa có folder → auto-tạo cha.

### 2d. Variant đã tồn tại ⬜
Upload ảnh đã có variant → `process()` ghi đè variant. Crop ảnh đã có variant → tạo theo `save_path`/đè.

---

## 3. RENAME / MOVE / COPY — collision (KHÁC upload: CÓ guard) ⬜
| Op | Đích chưa có | Đích đã có (file/folder) |
|---|---|---|
| rename/move/copy | 200 + kéo theo `_variants/*` + metadata | 4xx collision (`fileExists\|\|directoryExists`) |
| cross-copy/move (local→local, →s3) | 200 stream + transfer variant+metadata | verify hành vi đè |

Xoá ảnh phải xoá variant + dọn `_variants` rỗng.

---

## 4. Bảo mật / Claims (cơ bản ✅, mở rộng ⬜)
perms (read/write/delete → 403 đúng); disks (ngoài claim → 403); prefix scope (`../`, null byte, `..%2f` sanitize); path traversal + `_fluxfiles/`/`_variants/` (`assertNotSystem`); owner_only (user khác → 403, legacy no-owner → qua); BYOB (local BYOB bị từ chối; mixed local+r2); JWT (thiếu/sai/hết hạn → 401; **secret <32 byte → lỗi jwt v7**); CORS/Origin; rate limit (60 read/10 write → 429).

---

## 5. Visibility S3/R2 — public vs private (URL logic ✅ `test-visibility.php`)

> Đã fix: cấu hình `visibility` + `public_url` mỗi disk (`config/disks.php`, env `AWS_VISIBILITY/AWS_PUBLIC_URL/R2_VISIBILITY/R2_PUBLIC_URL`).

| Case | Mong đợi | Trạng thái |
|---|---|---|
| local | base URL `config['url']` | ✅ |
| s3 `private` (mặc định) | presigned GET (`X-Amz-Signature`) | ✅ |
| s3 `public` | URL bucket thẳng, không signature; write dùng ACL public-read | ✅ (URL) |
| s3 `public` + `public_url` | base CDN | ✅ |
| r2 `private` | presigned GET | ✅ |
| r2 `public` + `public_url` | custom domain | ✅ |
| r2 `public` không `public_url` | fallback endpoint/bucket | ✅ |
| **Live private bucket** presigned GET → HTTP 200; raw URL → 403 | `test-s3-live.php` | ✅ MinIO + AWS + R2 |
| **TTL presign** (`url_ttl`, default 3600, clamp 86400) | URL hết hạn đúng | ⬜ |
| **Live public bucket** raw URL → 200 | cần set bucket policy public | ⬜ |
| **Variant URL theo visibility** | variant private → presigned, public → thẳng | ⬜ |
| R2 `public` set ACL? | KHÔNG gọi ACL (R2 không hỗ trợ) | ✅ |

### Bug thật tìm được qua live test (đã fix)
1. **AWS bucket "Bucket owner enforced" (ACL disabled — mặc định AWS từ 4/2023)** → mọi write fail `AccessControlListNotSupported`. Fix: middleware strip param `ACL` cho mọi disk trừ AWS-public (`DiskManager`).
2. **R2 không trả ContentType qua HeadObject** → `fileMeta()` (`/api/fm/meta`) throw 500. Fix: `fileMeta()` chịu lỗi + fallback đoán mime theo extension.
3. **move/copy trước đây KHÔNG guard collision → ghi đè im lặng** (rename thì có). Đã FIX: thêm `assertTargetAvailable()` dùng chung cho rename/move/copy → tất cả trả 409 `name_exists` khi đích tồn tại. Test trong `test-images.php`.

---

## 6. Storage backends
- **Local**: full matrix (sidecar `.meta.json`, `_fluxfiles/index.json`, `dirs.json`, `audit.jsonl`). ✅ phần lớn
- **S3/R2 live** ✅ qua `test-s3-live.php` (env-gated, chạy với MinIO/AWS/R2): upload+variants, list, fileMeta, presigned GET 200, raw private 403, presign PUT→GET readback, delete. CI có job `s3-minio`.
- **Chunk multipart** (init/presign/complete/abort) cho file >10MB ⬜.

---

## 7. Wrapper packages (postMessage) ⬜
- **SDK**: mở/đóng iframe, `FM_CONFIG`, `FM_SELECT`, token refresh, close, locale/theme.
- **React/Vue**: mount/unmount, `useFluxFiles` commands (navigate/setDisk/refresh/search), Modal, ref imperatives, event subtypes.
- **CKEditor4/TinyMCE**: chèn URL ảnh đã chọn vào editor.
- **Laravel**: token + BYOB/mixed token, controller proxy asset, Blade component, auth→JWT.
- **WordPress**: REST `/wp-json/fluxfiles/v1/`, shortcode, media button modal, settings→disk config, self-host REST.
- **Browser e2e (Playwright)**: chọn ảnh có sẵn → `FM_SELECT` URL+variants; upload mới → thumbnail; crop inline; multi-select bulk; dark mode.

---

## 8. PHP version matrix (Docker)
Chạy `test-*.php` + `test-api.sh` trên **8.1, 8.2, 8.3, 8.4**. Xác nhận jwt v7 + flysystem v3 + intervention v3 resolve sạch, variant đúng, `composer audit` clean. (8.1/8.2 ✅; 8.3/8.4 ⬜.)

---

## 9. Edge / worst-case ⬜
Tên unicode/emoji/khoảng trắng/>255 ký tự/`#?&`; file cực lớn → chunk; ngắt giữa chừng → abort; upload đồng thời cùng tên (race, last-wins); quota chạm biên; FTS5 search dấu tiếng Việt/ký tự đặc biệt; ảnh CMYK/exif-rotated/animated gif; i18n 16 ngôn ngữ + RTL (ar) + key thiếu fallback.

---

## Ưu tiên triển khai
1. **`test-images.php`** — mục 1a/1b/1c/2b/2d (ảnh thật bằng GD đa kích thước/định dạng) + bổ sung collision vào `test-api.sh` (mục 3). Giá trị cao, chạy ngay trên host + Docker matrix.
2. **MinIO trong Docker** — mục 5/6 (S3/R2 thật + chunk + visibility live).
3. **vitest** cho React/Vue (mục 7).
