# FluxFiles — Hướng dẫn Test

Tài liệu hướng dẫn chạy test cho FluxFiles: API, i18n, BYOB, SDK.

---

## Chuẩn bị

```bash
cd /path/to/FluxFiles
composer install
cp .env.example .env
```

Cấu hình `.env`:

```env
FLUXFILES_SECRET=my-super-secret-key-for-testing-123
FLUXFILES_ALLOWED_ORIGINS=http://localhost:8080,http://localhost:3000
FLUXFILES_RATE_LIMIT_WRITE=100
```

---

## Chạy test từng loại

### 1. Test i18n (ngôn ngữ)

Kiểm tra file JSON, class I18n, placeholder:

```bash
php tests/test-i18n.php
```

Test thêm API i18n (cần server chạy):

```bash
php tests/test-i18n.php --api
```

### 2. Test BYOB (Bring Your Own Bucket)

Kiểm tra mã hóa credentials, Claims, DiskManager, token:

```bash
php tests/test-byob.php
```

### 3. Test API (HTTP)

**Yêu cầu:** Server chạy tại `http://localhost:8080`

```bash
# Terminal 1: Khởi động server (dùng router.php để route /api/*)
php -S localhost:8080 router.php

# Terminal 2: Chạy test
bash tests/test-api.sh
```

Test bao gồm: Auth, CORS, i18n, mkdir, upload, list, metadata, search, copy/move, trash, quota, audit, validation, cleanup.

### 4. Test SDK (fluxfiles.js)

**Yêu cầu:** Server chạy, mở trình duyệt:

```bash
php -S localhost:8080 -t .
# Mở: http://localhost:8080/tests/test-sdk.html
```

1. Chạy `php tests/generate-token.php` để lấy token
2. Dán token vào ô Authentication
3. Click "Open Picker" hoặc "Open Browser"
4. Kiểm tra Event Log, Commands, Locale

---

## Tạo token test

```bash
php tests/generate-token.php
```

Output: FULL TOKEN, READ-ONLY TOKEN, IMAGE-ONLY TOKEN, SCOPED TOKEN, QUOTA TOKEN, BYOB TOKEN, MIXED TOKEN.

---

## Chạy tất cả test (tự động)

```bash
# 1. Khởi động server
php -S localhost:8080 router.php &
SERVER_PID=$!
sleep 2

# 2. i18n
echo "=== i18n ==="
php tests/test-i18n.php
php tests/test-i18n.php --api

# 3. BYOB
echo "=== BYOB ==="
php tests/test-byob.php

# 4. API
echo "=== API ==="
bash tests/test-api.sh

# 5. Dừng server
kill $SERVER_PID 2>/dev/null || true
```

---

## Checklist nhanh

| Test | Lệnh | Kỳ vọng |
|------|------|---------|
| i18n | `php tests/test-i18n.php` | All passed |
| i18n API | `php tests/test-i18n.php --api` | 16 locales |
| BYOB | `php tests/test-byob.php` | All passed |
| API | `bash tests/test-api.sh` | All tests passed |
| SDK | Mở `tests/test-sdk.html` | Events, commands hoạt động |

---

## Cấu trúc thư mục tests

```
tests/
├── generate-token.php   # Tạo JWT test
├── test-api.sh          # Test API HTTP (curl)
├── test-byob.php        # Test BYOB encryption/Claims
├── test-i18n.php       # Test ngôn ngữ
└── test-sdk.html       # Test SDK (trình duyệt)
```
