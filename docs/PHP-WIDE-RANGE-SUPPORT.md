# Support FluxFiles trên PHP 7.4 → 8.4 (toàn bộ range)

Ngày: 2026-05-29
Tác giả: audit AI (Claude Opus 4.7)
Tài liệu liên quan:
- [PHP74-PORT-ANALYSIS.md](./PHP74-PORT-ANALYSIS.md) — chi tiết port xuống 7.4
- [PHP-VERSION-STRATEGY.md](./PHP-VERSION-STRATEGY.md) — chiến lược 7.4 vs 8.0 vs 8.1

---

## TL;DR

**Có cách**, và đây là kỹ thuật chuẩn của WordPress core / Symfony bundle / Sentry SDK. Có **3 approach** với cost-benefit khác nhau:

| Approach | Effort 1 lần | Maintain | Coverage thật | Recommend khi |
|---|---|---|---|---|
| **A. Lock dep cũ nhất** | ~5h | thấp | risky trên 8.4 | quick & dirty, không lo CI |
| **B. Composer constraint `\|\|` ranges + compat shim** ✅ | ~12h | trung | đầy đủ + đảm bảo | làm nghiêm túc, chạy production |
| **C. Polyfill + lockstep với platform** | ~8h | thấp | đầy đủ nhưng partial | đa số case dùng được |

**Khuyến nghị:** **Approach B** — đó là cách WordPress.org/Symfony làm. Effort lớn nhất 1 lần, nhưng ổn định và phải verify CI một lần là yên tâm.

---

## 1. Bản chất của "support range PHP rộng"

Composer hỗ trợ **platform-aware version resolution**: khi user `composer install` trên PHP 7.4, composer tự chọn version dep tương thích 7.4. Trên PHP 8.4, chọn version tương thích 8.4.

```json
{
  "require": {
    "php": ">=7.4",
    "firebase/php-jwt": "^5.5 || ^6.0"
  }
}
```

Composer logic:
- PHP 7.4 → chỉ v5.x match (vì v6 yêu cầu ^8.0) → install v5.5.x
- PHP 8.0+ → cả v5.x và v6.x đều match → composer chọn **cao nhất** = v6.x
- Nhưng v5 và v6 có API khác nhau ⇒ **code FluxFiles phải handle cả hai API**

Đây là chỗ **compat shim** vào.

---

## 2. Approach A — Lock dep cũ nhất, hi vọng nó chạy được trên PHP 8.4

### Cách làm

Pin tất cả dep ở phiên bản cũ nhất hỗ trợ PHP 7.4:

```json
"require": {
    "php": ">=7.4",
    "firebase/php-jwt": "^5.5",
    "league/flysystem": "^2.4",
    "league/flysystem-aws-s3-v3": "^2.4",
    "aws/aws-sdk-php": ">=3.250,<3.295",
    "intervention/image": "^2.7",
    "vlucas/phpdotenv": "^5.0",
    "symfony/polyfill-php80": "^1.28"
}
```

Test trên PHP 7.4 thấy chạy. Đẩy lên Packagist. Xong.

### Vấn đề thực tế

Trên **PHP 8.4** (release 11/2024):
- Deprecated `null` to non-nullable internal param — Flysystem v2 dùng nhiều `?` deprecated.
- Deprecated implicit nullable — code Intervention v2 có signature như `function foo($x = null)` (không typed) sẽ emit deprecation.
- `mt_rand()` không deterministic warning ở v2 jwt.

Hệ quả:
- App vẫn **chạy** nhưng log đầy deprecation notice.
- WordPress 6.7+ có thể hiện debug bar warning, scare user.
- Khả năng bug đặc thù PHP 8.4 trong dep cũ → ai bị thì khó debug vì maintainer dep đã EOL.

### Khi nào chọn

- Bạn không quan tâm log warning.
- User base chủ yếu trên 8.0-8.3, 8.4 là minority.
- Sẵn sàng patch tay nếu user trên 8.4 báo lỗi.

### Effort

~5h port (đã viết ở `PHP74-PORT-ANALYSIS.md`).

---

## 3. Approach B — Composer ranges + compat shim ✅ Khuyến nghị

### Cách làm

#### 3.1. composer.json với version ranges

```json
"require": {
    "php": ">=7.4",
    "firebase/php-jwt": "^5.5 || ^6.0",
    "league/flysystem": "^2.4 || ^3.0",
    "league/flysystem-aws-s3-v3": "^2.4 || ^3.0",
    "aws/aws-sdk-php": "^3.0",
    "intervention/image": "^2.7 || ^3.0",
    "vlucas/phpdotenv": "^5.0 || ^6.0",
    "symfony/polyfill-php80": "^1.28"
}
```

Composer chọn dep phù hợp:

| PHP runtime | jwt | flysystem | intervention | aws-sdk |
|---|---|---|---|---|
| 7.4 | v5.5 | v2.4 | v2.7 | 3.290.x (cuối hỗ trợ 7.4) |
| 8.0 | v6.x | v3.x | v2.7 (vì v3 cần 8.1) | 3.x mới |
| 8.1 | v6.x | v3.x | v3.x | 3.x mới |
| 8.2 | v6.x | v3.x | v3.x | 3.x mới |
| 8.3 | v6.x | v3.x | v3.x | 3.x mới |
| 8.4 | v6.x | v3.x | v3.x | 3.x mới |

#### 3.2. Compat shim — wrap mỗi dep có breaking API

**JWT shim:**

```php
// packages/core/api/JwtCompat.php (mới)
namespace FluxFiles;

class JwtCompat
{
    /** Encode payload với 1 secret, alg HS256. Signature giống nhau v5/v6. */
    public static function encode(array $payload, string $secret): string
    {
        return \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');
    }

    /** Decode — abstract sự khác biệt v5/v6 */
    public static function decode(string $token, string $secret): object
    {
        // v6 has the Key class; v5 does not.
        if (class_exists('\Firebase\JWT\Key')) {
            return \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
        }
        // v5 signature
        return \Firebase\JWT\JWT::decode($token, $secret, ['HS256']);
    }
}
```

Sau đó code FluxFiles chỉ gọi `JwtCompat::decode()` / `JwtCompat::encode()` — 1 interface duy nhất.

**Intervention Image shim:**

```php
// packages/core/api/ImageCompat.php (mới)
namespace FluxFiles;

class ImageCompat
{
    private $manager;
    private $isV3;

    public function __construct()
    {
        if (class_exists('\Intervention\Image\Drivers\Gd\Driver')) {
            // v3
            $this->manager = new \Intervention\Image\ImageManager(
                new \Intervention\Image\Drivers\Gd\Driver()
            );
            $this->isV3 = true;
        } else {
            // v2
            $this->manager = new \Intervention\Image\ImageManager(['driver' => 'gd']);
            $this->isV3 = false;
        }
    }

    public function read($data) { return $this->isV3 ? $this->manager->read($data) : $this->manager->make($data); }

    public function encodeWebp($image, int $q = 80): string {
        return $this->isV3 ? (string) $image->toWebp($q) : (string) $image->encode('webp', $q);
    }

    public function encodeJpeg($image, int $q = 85): string {
        return $this->isV3 ? (string) $image->toJpeg($q) : (string) $image->encode('jpg', $q);
    }

    public function encodePng($image): string {
        return $this->isV3 ? (string) $image->toPng() : (string) $image->encode('png');
    }

    public function scaleDown($image, int $maxWidth) {
        if ($this->isV3) {
            return $image->scaleDown($maxWidth);
        }
        return $image->resize($maxWidth, null, function ($c) {
            $c->aspectRatio();
            $c->upsize();
        });
    }
}
```

`ImageOptimizer.php` sửa lại dùng `ImageCompat` thay vì gọi trực tiếp Intervention.

**Flysystem:** v2 và v3 cùng có `FileAttributes`/`StorageAttributes`/`listContents`/`directoryExists`/v.v. → **không cần shim**.

**AWS SDK:** API stable trên 3.x → **không cần shim**.

#### 3.3. Polyfill cho str_*

```json
"require": { "symfony/polyfill-php80": "^1.28" }
```

Tự động cung cấp `str_contains`, `str_starts_with`, `str_ends_with` trên PHP 7.4. Trên PHP 8.0+ native được dùng (polyfill no-op).

#### 3.4. Named args trong tests → positional

Đổi `packages/core/tests/generate-token.php` về positional. Hoặc bỏ tests này (chúng chỉ là demo) và viết bằng PHPUnit nếu cần.

### 3.5. Test matrix CI

GitHub Actions config:

```yaml
strategy:
  matrix:
    php: ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4']
steps:
  - uses: shivammathur/setup-php@v2
    with:
      php-version: ${{ matrix.php }}
  - run: composer install -d packages/core
  - run: php packages/core/tests/test-claims.php
  - run: php packages/core/tests/test-metadata.php
  # ... v.v.
```

CI run 6 phiên bản song song mỗi push. Bất cứ commit nào breaking 1 version sẽ red ngay.

### Effort

~12h:
- Compat shim JWT + Intervention: ~3h
- Refactor `JwtMiddleware`, `ImageOptimizer`, gọi shim: ~2h
- composer.json 3 package: ~30 phút
- CI matrix setup: ~2h
- Test trên Docker 6 PHP version + debug edge case: ~4h

### Pros

- Coverage **100% range** 7.4 → 8.4 với assurance từ CI.
- User trên PHP 8.4 vẫn được dùng lib mới nhất (Intervention v3, JWT v6).
- User trên PHP 7.4 vẫn install được.
- Truyền thông điệp đúng: "FluxFiles supports PHP 7.4 through 8.4".

### Cons

- Code phức tạp hơn (2 class shim).
- Maintain CI matrix.
- Khi nâng cấp lib lớn (vd Intervention v4 ra), phải xét tương thích thêm 1 lần.

### Khi nào chọn

- Muốn truyền thông điệp professional, broad support.
- Có CI budget.
- Code base sẽ live ≥ 2 năm.

**Đây là cách `mpdf/mpdf`, `phpoffice/phpspreadsheet`, `sentry/sentry` đang làm.**

---

## 4. Approach C — Polyfill + version lock theo platform

### Cách làm

Dùng composer "platform" lock: pin dep ở phiên bản **không có breaking change** trên range PHP.

Ví dụ:
- `firebase/php-jwt: ^5.5` — v5.5.x được patch cho PHP 8.x compatibility. Hi vọng nó chạy được trên 8.4 mà không có deprecation lớn.
- `intervention/image: ^2.7` — v2.7 active maintenance, có patches PHP 8.x.
- `league/flysystem: ^2.4` — last v2, kém maintained.

Khác Approach A ở chỗ: chấp nhận **không có v6/v3 mới**, nhưng dùng latest patch của v5/v2 nhất có thể.

### Pros

- Đơn giản — 1 dep set duy nhất.
- Không có compat shim code.

### Cons

- Bị bỏ lại tính năng mới (v6 jwt có Key class an toàn hơn, v3 Intervention có API sạch hơn).
- Khi maintainer của lib cũ ngừng release → kẹt.

### Khi nào chọn

- App lifecycle ngắn (< 1 năm).
- Không cần feature mới của dep.
- Không muốn maintain shim.

---

## 5. So sánh chi tiết

| Tiêu chí | A. Lock cũ | **B. Range + shim** ✅ | C. Polyfill + lock |
|---|---|---|---|
| Effort 1 lần | 5h | 12h | 8h |
| Maintain dài hạn | Thấp | Trung | Thấp |
| Coverage PHP 7.4 | ✅ | ✅ | ✅ |
| Coverage PHP 8.4 | risky (warnings) | ✅ tested | risky |
| User 8.4 được lib mới | ❌ | ✅ | ❌ |
| Cần CI matrix | optional | yes | optional |
| Truyền thông điệp | "supports legacy" | "supports 7.4-8.4" | "supports 7.4-8.4 (limited)" |
| Dep EOL kéo theo | nhiều | 1 (jwt v5 chỉ user 7.4) | nhiều |
| Compat code | không | 2 shim class | không |
| Khi lib breaking change | refactor lớn | chỉ update shim | kẹt |

---

## 6. Khuyến nghị cụ thể

**Nếu muốn "support tất cả 7.4 → 8.4" thực sự, dùng Approach B.** Cụ thể:

### 6.1. Plan execute (mở rộng từ PHP74-PORT-ANALYSIS.md)

**Phase A — Compat shims (~3h)**
- Tạo `packages/core/api/JwtCompat.php`
- Tạo `packages/core/api/ImageCompat.php`
- Test cả 2 trên PHP 8.2 hiện tại với cả 2 dep version

**Phase B — Refactor sites (~2h)**
- `JwtMiddleware::handle` → gọi `JwtCompat::decode`
- `embed.php`, `FluxFilesManager`, `FluxFilesPlugin` → gọi `JwtCompat::encode`
- `ImageOptimizer` → gọi `ImageCompat`

**Phase C — composer.json (~30 phút)**
- 3 package: `php: ">=7.4"`
- Range constraints như §3.1
- Add `symfony/polyfill-php80`
- WP plugin header: `Requires PHP: 7.4`

**Phase D — Code housekeeping (~30 phút)**
- Tests/generate-token.php: positional args
- Xác nhận grep không còn 8.1+ syntax

**Phase E — CI matrix (~2h)**
- `.github/workflows/test.yml` với 6 PHP versions
- Run all `tests/test-*.php`
- Run `bash tests/test-api.sh` (cần PHP server)

**Phase F — Smoke test trong Docker (~4h)**
- Docker image cho 7.4, 8.0, 8.1, 8.2, 8.3, 8.4
- `composer install` từng cái
- Chạy server + curl smoke + upload ảnh + verify variants
- WP plugin test trong wp-now với `--php=7.4/8.0/.../8.4`

**Tổng: ~12h**

### 6.2. Composer constraint cuối cùng

`packages/core/composer.json`:
```json
{
  "name": "fluxfiles/fluxfiles",
  "require": {
    "php": ">=7.4",
    "ext-gd": "*",
    "ext-curl": "*",
    "ext-json": "*",
    "ext-openssl": "*",
    "ext-mbstring": "*",
    "ext-fileinfo": "*",
    "symfony/polyfill-php80": "^1.28",
    "firebase/php-jwt": "^5.5 || ^6.0",
    "league/flysystem": "^2.4 || ^3.0",
    "league/flysystem-aws-s3-v3": "^2.4 || ^3.0",
    "aws/aws-sdk-php": "^3.0",
    "intervention/image": "^2.7 || ^3.0",
    "vlucas/phpdotenv": "^5.0 || ^6.0"
  }
}
```

`packages/laravel/composer.json`:
```json
"require": {
    "php": ">=7.4",
    "fluxfiles/fluxfiles": "^1.27",
    "illuminate/support": "^8.0|^9.0|^10.0|^11.0|^12.0",
    "illuminate/routing": "^8.0|^9.0|^10.0|^11.0|^12.0",
    "illuminate/view": "^8.0|^9.0|^10.0|^11.0|^12.0"
}
```

`packages/wordpress/composer.json`:
```json
"require": {
    "php": ">=7.4",
    "fluxfiles/fluxfiles": "^1.27"
}
```

`packages/wordpress/fluxfiles.php` header:
```
Requires PHP: 7.4
```

---

## 7. Caveat quan trọng

Approach B vẫn không né được rủi ro **PHP 7.4 EOL** ở runtime level:
- Nếu user chạy PHP 7.4 trên production, họ có nguy cơ CVE PHP-level (không có patch upstream).
- Compat shim không bảo vệ được điều này.

⇒ **Nên kèm warning trong README:**
> FluxFiles supports PHP 7.4 through 8.4. PHP 7.4 has reached end-of-life as of November 2022; we recommend upgrading to PHP 8.1 or newer for security updates.

---

## 8. Quyết định bạn ra

| Câu hỏi | Lựa chọn |
|---|---|
| Đi Approach B (range + shim)? | yes / no |
| Set up CI matrix luôn? | yes (recommended) / sau |
| README warning về 7.4 EOL? | yes / no |

Nếu yes hết, tôi tiến hành theo Plan §6.1 với checkpoint mỗi phase.
