# Phân tích Port FluxFiles xuống PHP 7.4

Ngày: 2026-05-29
Tác giả: audit AI (Claude Opus 4.7)
Trạng thái: **proposal — chờ quyết định**

---

## TL;DR

- Hiện trạng: **toàn bộ 3 package PHP yêu cầu PHP 8.1+** (composer constraint + WP plugin header + dependency).
- Để hỗ trợ PHP 7.4, cần thay đổi **code (nhẹ) + dependencies (vừa) + framework adapter constraints (vừa)**.
- **Khả thi**, không phải refactor lớn ngoài 1 file `ImageOptimizer.php`. Flysystem 3 → 2 gần như zero refactor (bất ngờ).
- **Rủi ro chính không nằm ở code**, mà ở:
  1. Lùi security posture (firebase/php-jwt v5 EOL, Laravel 8 EOL, PHP 7.4 EOL).
  2. Khả năng bug subtle giữa Intervention v2 và v3 ở chất lượng/format ảnh.
- Tổng effort ước tính: **1 ngày code + 0.5 ngày test trong Docker PHP 7.4**.

---

## 1. Hiện trạng đang yêu cầu PHP 8.1+

### 1.1. Composer constraints

| File | Khai báo |
|---|---|
| `packages/core/composer.json` | `"php": "^8.1"` |
| `packages/laravel/composer.json` | `"php": "^8.1"` |
| `packages/wordpress/composer.json` | `"php": "^8.1"` |

### 1.2. WordPress plugin header

`packages/wordpress/fluxfiles.php`:
```
* Requires PHP: 8.1
```

### 1.3. README

```
PHP >= 8.1 (Flysystem 3 + Intervention Image v3; tested with 8.1 — 8.3)
```

---

## 2. Audit feature PHP 8.x đang dùng trong code

### 2.1. PHP 8.0 built-in functions — **23 occurrences**

| Hàm | Bắt đầu có | Cách backport |
|---|---|---|
| `str_contains($haystack, $needle)` | 8.0 | `strpos($haystack, $needle) !== false` |
| `str_starts_with($haystack, $needle)` | 8.0 | `strpos($haystack, $needle) === 0` |
| `str_ends_with($haystack, $needle)` | 8.0 | `substr($haystack, -strlen($needle)) === $needle` |

**Vị trí cụ thể:**
- `packages/core/api/StorageMetadataHandler.php`: line 212, 229, 252, 273, 300, 359, 390-393, 677
- `packages/core/api/FileManager.php`: line 188-191
- `packages/core/api/ExistingFileIndexer.php`: line 67, 135-138
- (chi tiết đầy đủ qua `grep`)

**Phương án thay thế khác:** thêm `symfony/polyfill-php80` vào `require` của core composer.json. Khi đó toàn bộ 23 chỗ không phải đụng vào.

**Khuyến nghị:** **dùng polyfill** vì:
- Phạm vi an toàn — semantics 100% giống native.
- Không phải đụng vào code logic.
- Maintainer của polyfill là Symfony — production-grade.
- 1 dòng config thay vì 23 edit.

### 2.2. Named arguments (PHP 8.0)

```php
// packages/core/embed.php:187-191
endpoint: "{$endpoint}",
token: "{$token}",
disk: "{$disk}",
mode: "{$mode}",
container: "#fluxfiles-container"
```

Đây thực ra là JSON literal trong HEREDOC, KHÔNG phải named args PHP. **False positive** từ grep regex. ✅

```php
// packages/core/tests/generate-token.php
$fullToken = fluxfiles_token(
    userId:      'test-user-001',
    perms:       ['read', 'write', 'delete'],
    ...
);
```

Đây **là** named args thật, nhưng **chỉ trong tests**, không phải code production. Cần thay positional khi chạy test trên PHP 7.4, nhưng có thể giữ named cho dev experience trên PHP 8.x bằng cách:
- Có 2 file: `generate-token.php` (positional) + `generate-token-8.php` (named, optional)
- Hoặc đơn giản: chuyển tất cả về positional.

**Khuyến nghị:** chuyển tất cả về positional + giữ doc comment giải thích thứ tự param.

### 2.3. Các feature PHP 8.0+/8.1+ KHÔNG dùng

Audit confirm sạch:

| Feature | Bắt đầu có | Có dùng? |
|---|---|---|
| `match` expression | 8.0 | ❌ |
| Nullsafe `?->` | 8.0 | ❌ |
| Constructor property promotion | 8.0 | ❌ |
| `mixed` type | 8.0 | ❌ (chỉ trong docblock) |
| `readonly` properties | 8.1 | ❌ (chỉ làm tên option) |
| Enums | 8.1 | ❌ |
| `never` return type | 8.1 | ❌ |
| First-class callable `func(...)` | 8.1 | ❌ |
| `new` in initializer | 8.1 | ❌ |
| Intersection types | 8.1 | ❌ |

⇒ **Code thực tế chạy được trên PHP 7.4 sau khi:**
1. Add polyfill cho 3 hàm str_*.
2. Thay named args trong test files.

---

## 3. Audit dependencies

### 3.1. Yêu cầu PHP của từng dep hiện tại

| Package | Hiện tại | PHP yêu cầu | Phải downgrade? |
|---|---|---|---|
| `firebase/php-jwt` | `^6.0` | `^8.0` | → `^5.5` |
| `league/flysystem` | `^3.0` | `^8.0.2` | → `^2.4` |
| `league/flysystem-aws-s3-v3` | `^3.0` | `^8.0.2` | → `^2.4` |
| `aws/aws-sdk-php` | `^3.378` | `>=8.1` | → `~3.290` |
| `intervention/image` | `^3.0` | `^8.1` | → `^2.7` |
| `vlucas/phpdotenv` | `^5.0` | `^7.2.5 \|\| ^8.0` | **không cần** |

### 3.2. Mức độ refactor mỗi dep gây ra

#### 3.2.1. firebase/php-jwt v6 → v5 (RỦI RO THẤP, 1 dòng)

**Chỉ 1 call site đổi:**

```php
// packages/core/api/JwtMiddleware.php:15 (hiện tại v6)
$payload = JWT::decode($token, new Key($secret, 'HS256'));

// v5
$payload = JWT::decode($token, $secret, ['HS256']);
```

`JWT::encode($payload, $secret, 'HS256')` — signature **giống hệt** v5/v6, **không đổi**. 5 call site `JWT::encode` ở `embed.php`, `FluxFilesPlugin.php`, `FluxFilesManager.php` đều không đụng vào.

**Cảnh báo security:** v5 đã EOL (2022). CVE-2021-46743 (algorithm confusion) chỉ fix ở v6. Code FluxFiles luôn truyền algorithm cố định `'HS256'` nên không trúng CVE đó cụ thể, nhưng vẫn là regression posture.

#### 3.2.2. intervention/image v3 → v2 (RỦI RO TRUNG BÌNH, 1 file)

**Diff đóng kín trong** `packages/core/api/ImageOptimizer.php` (109 dòng). Mapping API:

| v3 (hiện tại) | v2 (target) |
|---|---|
| `use Intervention\Image\ImageManager;` | giữ nguyên |
| `use Intervention\Image\Drivers\Gd\Driver as GdDriver;` | bỏ |
| `new ImageManager(new GdDriver())` | `new ImageManager(['driver' => 'gd'])` |
| `$manager->read($data)` | `$manager->make($data)` |
| `$image->toWebp(80)` | `$image->encode('webp', 80)` |
| `$image->toJpeg(85)` | `$image->encode('jpg', 85)` |
| `$image->toPng()` | `$image->encode('png')` |
| `$image->scaleDown($maxWidth)` | `$image->resize($maxWidth, null, fn($c) => { $c->aspectRatio(); $c->upsize(); })` |
| `$image->crop($w, $h, $x, $y)` | `$image->crop($w, $h, $x, $y)` ✅ giống |
| `$image->width()` / `height()` | ✅ giống |

**Rủi ro:**
- Default compression quality có thể khác 1-2% giữa hai lib. Không ảnh hưởng functional, có thể ảnh hưởng byte-diff so sánh.
- v2 không có driver Imagick "discovery" hiện đại như v3 — phải khai báo driver tường minh (đã làm).
- v2 cho phép overwrite ảnh gốc tại memory bằng `make()`, behavior giống v3 `read()`. Stream API có khác biệt nhỏ — code hiện không dùng stream cho image.

#### 3.2.3. league/flysystem v3 → v2 (RỦI RO RẤT THẤP, gần như zero refactor)

**Bất ngờ:** typed `FileAttributes` / `StorageAttributes` đã được introduce ở **v2.0** (không phải v3). Toàn bộ API surface đang dùng đều có ở v2:

| API đang dùng | Có ở v2? |
|---|---|
| `League\Flysystem\Filesystem` | ✅ |
| `League\Flysystem\Local\LocalFilesystemAdapter` | ✅ |
| `League\Flysystem\AwsS3V3\AwsS3V3Adapter` | ✅ |
| `$fs->listContents($path, $deep)` returning `DirectoryListing` | ✅ |
| `League\Flysystem\FileAttributes` typed object | ✅ |
| `League\Flysystem\StorageAttributes` typed object | ✅ |
| `$item->path()`, `->isFile()`, `->isDir()` | ✅ |
| `$item->fileSize()`, `->lastModified()` (nullable) | ✅ |
| `fileExists()`, `directoryExists()` | ✅ |
| `createDirectory()`, `deleteDirectory()` | ✅ |
| `readStream()`, `writeStream()` | ✅ |
| `read()`, `write()`, `copy()`, `move()`, `delete()` | ✅ |
| `mimeType()` | ✅ |
| `new Filesystem($adapter, ['retain_visibility' => false])` | ✅ |

⚠️ **Cần verify thực tế** (sẽ làm trong Docker test):
- Behavior khi listContents folder không tồn tại: v3 throw `UnableToListContents`, v2 cũng throw (interface giống). OK.
- Behavior khi delete file không tồn tại: cả 2 throw — code hiện đã try/catch.
- S3 visibility handling: v2 và v3 có thay đổi ở phần config visibility default — code hiện set `retain_visibility => false`, đủ safe.

**Conclusion: 0 code change cho Flysystem.**

#### 3.2.4. aws/aws-sdk-php (RỦI RO RẤT THẤP, chỉ pin version)

API S3 đang dùng (`S3Client`, `createMultipartUpload`, `completeMultipartUpload`, `abortMultipartUpload`, `headObject`, `copyObject`, `getCommand`, `createPresignedRequest`) **ổn định suốt 3.x lifecycle**.

Cần pin tối đa `~3.290.x` — version cuối còn support PHP 7.4. (AWS bump PHP 8.1 minimum vào ~3.295 cuối 2023).

**0 code change.**

#### 3.2.5. vlucas/phpdotenv (NO CHANGE)

`^7.2.5 || ^8.0` đã support PHP 7.4 luôn. Constraint `^5.0` của FluxFiles giữ nguyên.

---

## 4. Framework adapter constraints

### 4.1. Laravel package

Hiện tại:
```json
"illuminate/support": "^10.0|^11.0|^12.0"
```

| Laravel ver | PHP yêu cầu | Status |
|---|---|---|
| 8.x | 7.3+ | EOL từ 1/2023 |
| 9.x | 8.0+ | EOL từ 2/2024 |
| 10.x | 8.1+ | active |
| 11.x | 8.2+ | active |
| 12.x | 8.2+ | active |

Cho PHP 7.4 → **chỉ có Laravel 8 đủ điều kiện**, mà Laravel 8 EOL rồi. Constraint mới:
```json
"illuminate/support": "^8.0|^9.0|^10.0|^11.0|^12.0"
```

Code `FluxFilesManager`, `FluxFilesServiceProvider`, `FluxFilesController` hiện không dùng feature Laravel 9+ specific (no enums, no readonly, no PHP 8 union types). **0 code change**.

### 4.2. WordPress plugin

`Requires PHP: 8.1` → `7.4`.
WP 5.6+ chính thức support PHP 7.4. WP 6.x cũng OK.
Tested up to: cần bump theo WP version testing.

---

## 5. Rủi ro tổng hợp + cách giảm thiểu

| Rủi ro | Mức độ | Mitigation |
|---|---|---|
| `str_contains/_starts_with/_ends_with` thay sai semantics | Thấp | Dùng symfony/polyfill-php80 thay vì rewrite |
| JWT v5 thiếu fix CVE algorithm confusion | Trung (security) | Tất cả call site đều hardcode `'HS256'`, không có path nào để user inject alg |
| Intervention v2 output quality khác v3 | Thấp | Test bằng ảnh thật, so sánh kích thước file hợp lý |
| Flysystem v2 edge case khác v3 | Thấp | Test test-api.sh trên container PHP 7.4 |
| AWS SDK pin version stale | Thấp | Version 3.290 stable, có bug fix lớn nào sau đó tracking riêng |
| Laravel 8 EOL | Trung (security) | Document rõ trong README |
| **PHP 7.4 EOL từ 2022-11** | **Cao (security)** | **Document rõ**, khuyến cáo dùng PHP 8.x nếu được |
| Build WP plugin ZIP với dep PHP 7.4 chạy trên host PHP 8.x | Trung | Test ZIP install trên cả 7.4 và 8.2 |

---

## 6. Plan thực thi 4 phase + checkpoint

### Phase A — Mechanical code change (chạy được trên PHP 8.x để verify không phá behavior)

A1. `composer require symfony/polyfill-php80` trong `packages/core/composer.json` → 23 chỗ `str_*` tự dùng polyfill.

A2. Chuyển named args trong `packages/core/tests/generate-token.php` về positional.

**Checkpoint A:** chạy `php -l` toàn repo + chạy tất cả `tests/test-*.php` trên PHP 8.2 hiện tại. Phải pass 100% (chứng tỏ refactor không phá behavior).

### Phase B — Rewrite ImageOptimizer cho Intervention v2

B1. Viết lại `packages/core/api/ImageOptimizer.php` dùng API v2.

B2. Tạm thời `composer require intervention/image: ^2.7` (vẫn dùng PHP 8.2 host).

**Checkpoint B:** upload 1 ảnh PNG thật qua API, verify 3 variant (thumb/medium/large) tạo đúng kích thước + đọc lại được, crop hoạt động. Vẫn chạy trên PHP 8.2.

### Phase C — composer.json + dependency downgrade

C1. Cập nhật tất cả `php` constraint xuống `>=7.4` ở 3 package.

C2. Cập nhật dep:
- `firebase/php-jwt: ^5.5`
- `league/flysystem: ^2.4`
- `league/flysystem-aws-s3-v3: ^2.4`
- `aws/aws-sdk-php: >=3.250,<3.291`
- `intervention/image: ^2.7`

C3. Cập nhật `JwtMiddleware::handle` cho API v5 (1 dòng).

C4. WP plugin header `Requires PHP: 7.4`.

C5. Laravel constraint mở rộng xuống `^8.0`.

**Checkpoint C:** `composer install -d packages/core` trên PHP 8.2 host vẫn thành công (vì v5/v2/v2/^2.7 đều hỗ trợ PHP 8.x lên trên). Chạy lại test suite, vẫn pass.

### Phase D — Test thực tế trong Docker PHP 7.4

D1. Dockerfile dựa `php:7.4-cli` + extension `gd`, `zip`, `mbstring`, `fileinfo`, `curl`, `json`, `openssl` + composer.

D2. `composer install -d packages/core` **TRONG container PHP 7.4**.

D3. Chạy lần lượt:
- `php tests/test-claims.php`
- `php tests/test-metadata.php`
- `php tests/test-i18n.php`
- `php tests/test-ratelimiter.php`
- `php tests/test-diskmanager.php`
- `php tests/test-owner-only.php`
- `php tests/test-byob.php`
- `php tests/test-existing-indexer.php`

D4. Khởi `php -S 0.0.0.0:8088 router.php` trong container, expose port. Smoke test từ host:
- list, mkdir, upload (text + image), search, delete
- dedup detection
- DELETE 404 (Bug 3 regression)
- force_upload='false' dedup (Bug 2 regression)

D5. Build WP plugin ZIP bằng `scripts/build-wordpress.sh`, install trong wp-now PHP 7.4 (`--php=7.4`), full smoke test.

**Checkpoint D:** tất cả test PHP PASS + smoke test API trả về kết quả đúng + WP plugin trong wp-now 7.4 load + iframe + upload/list hoạt động.

---

## 7. Estimate

| Phase | Effort | Risk if fail |
|---|---|---|
| A | 30 phút | 0 — polyfill là drop-in |
| B | 1.5h | Intervention v2 quirks ở edge case (rare format) |
| C | 30 phút | Dependency conflict — composer tự báo |
| D | 2-3h | Subtle bug trong runtime PHP 7.4 (typed prop init, void return, … — code hiện sạch nên kỳ vọng OK) |

**Tổng: ~5h hands-on + 1-2h buffer cho debug.**

---

## 8. Quyết định cần bạn ra

### 8.1. Tiến hành port không?

| Lựa chọn | Khi nào chọn |
|---|---|
| **Có, full port** | Có khách hàng/deployment ràng buộc PHP 7.4 không thể bump |
| **Không, giữ 8.1+** | Không có nhu cầu ngắn hạn — PHP 7.4 EOL, không nên đầu tư |
| **POC trước** | Muốn xem thử Phase A+C chạy được không trước cam kết hết |

### 8.2. Nếu tiến hành, có dùng polyfill không?

| Lựa chọn | Pros / Cons |
|---|---|
| **Dùng symfony/polyfill-php80** ✅ recommended | Pros: 1 dep, code không sửa. Cons: thêm 1 dep maintenance |
| Rewrite tay 23 chỗ | Pros: 0 dep mới. Cons: 23 chỗ phải review, dễ sót edge case |

### 8.3. Có chấp nhận lùi 3 thứ EOL không?

- PHP 7.4 EOL
- firebase/php-jwt v5 EOL
- Laravel 8 EOL

Nếu bắt buộc support → chấp nhận, **doc rõ trong README** để khách biết.

---

## 9. Phụ lục — câu lệnh để re-audit nhanh

```bash
# str_contains/starts/ends_with
grep -rn "str_contains\|str_starts_with\|str_ends_with" \
  packages/core/api packages/core/embed.php \
  packages/wordpress/includes packages/laravel/src

# Constructor property promotion
grep -rnE "function __construct\([^)]*\b(public|protected|private)\b" \
  packages/core packages/wordpress/includes packages/laravel/src

# Nullsafe / match / readonly / enum / first-class callable
grep -rnE "\?->|^\s*match\s*\(|\breadonly\b\s+\w|\benum\s+\w|\b\w+\(\.\.\.\)" \
  packages/core packages/wordpress packages/laravel

# Per-dep PHP requirement
for d in firebase/php-jwt league/flysystem league/flysystem-aws-s3-v3 \
         aws/aws-sdk-php intervention/image vlucas/phpdotenv; do
  jq -r '.require.php // "(any)"' packages/core/vendor/$d/composer.json \
    | xargs -I{} printf "%-40s %s\n" "$d" "{}"
done
```
