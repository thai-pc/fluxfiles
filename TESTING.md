# FluxFiles — Huong dan Test Day du

Tai lieu nay huong dan test toan bo tinh nang cua FluxFiles, tu backend API, authentication, file operations, i18n, cho den frontend UI va cac adapter.

---

## Muc luc

1. [Chuan bi moi truong](#1-chuan-bi-moi-truong)
2. [Test Authentication & Token](#2-test-authentication--token)
3. [Test File Operations (API)](#3-test-file-operations-api)
4. [Test Metadata & SEO](#4-test-metadata--seo)
5. [Test Search (FTS5)](#5-test-search-fts5)
6. [Test Trash (Soft Delete)](#6-test-trash-soft-delete)
7. [Test Image Optimization & Crop](#7-test-image-optimization--crop)
8. [Test AI Auto-Tag](#8-test-ai-auto-tag)
9. [Test Cross-Disk Copy/Move](#9-test-cross-disk-copymove)
10. [Test Chunk Upload (S3 Multipart)](#10-test-chunk-upload-s3-multipart)
11. [Test Rate Limiting](#11-test-rate-limiting)
12. [Test Quota](#12-test-quota)
13. [Test Audit Log](#13-test-audit-log)
14. [Test Internationalization (i18n)](#14-test-internationalization-i18n)
15. [Test Frontend UI](#15-test-frontend-ui)
16. [Test SDK (fluxfiles.js)](#16-test-sdk-fluxfilesjs)
17. [Test React Adapter](#17-test-react-adapter)
18. [Test Laravel Adapter](#18-test-laravel-adapter)
19. [Test WordPress Adapter](#19-test-wordpress-adapter)
20. [Test Security](#20-test-security)
21. [Test Script Tu dong](#21-test-script-tu-dong)

---

## 1. Chuan bi moi truong

### 1.1 Cai dat

```bash
cd /path/to/FluxFiles
composer install
cp .env.example .env
```

### 1.2 Cau hinh `.env`

```env
# BAT BUOC — random string 32+ ky tu
FLUXFILES_SECRET=my-super-secret-key-for-testing-123

# CORS — cho phep localhost
FLUXFILES_ALLOWED_ORIGINS=http://localhost:8080,http://localhost:3000

# Locale (tuy chon)
FLUXFILES_LOCALE=

# AI (tuy chon — de test AI tagging)
FLUXFILES_AI_PROVIDER=openai
FLUXFILES_AI_API_KEY=sk-xxxx
FLUXFILES_AI_MODEL=gpt-4o
FLUXFILES_AI_AUTO_TAG=false
```

### 1.3 Khoi dong dev server

```bash
php -S localhost:8080 -t .
```

### 1.4 Tao token test

Tao file `tests/generate-token.php`:

```php
<?php
require_once __DIR__ . '/../embed.php';

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Token day du quyen
$fullToken = fluxfiles_token(
    userId:      'test-user-001',
    perms:       ['read', 'write', 'delete'],
    disks:       ['local', 's3', 'r2'],
    prefix:      '',
    maxUploadMb: 50,
    allowedExt:  null,
    ttl:         86400  // 24h
);
echo "FULL TOKEN:\n{$fullToken}\n\n";

// Token chi doc
$readToken = fluxfiles_token(
    userId: 'reader-001',
    perms:  ['read'],
    disks:  ['local'],
    ttl:    3600
);
echo "READ-ONLY TOKEN:\n{$readToken}\n\n";

// Token gioi han extension
$imageToken = fluxfiles_token(
    userId:     'uploader-001',
    perms:      ['read', 'write'],
    disks:      ['local'],
    allowedExt: ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    maxUploadMb: 5,
    ttl:         3600
);
echo "IMAGE-ONLY TOKEN:\n{$imageToken}\n\n";

// Token co path prefix (scoped)
$scopedToken = fluxfiles_token(
    userId: 'scoped-user',
    perms:  ['read', 'write', 'delete'],
    disks:  ['local'],
    prefix: 'users/scoped-user/',
    ttl:    3600
);
echo "SCOPED TOKEN (prefix=users/scoped-user/):\n{$scopedToken}\n\n";

// Token co quota
$quotaToken = fluxfiles_token(
    userId:      'quota-user',
    perms:       ['read', 'write'],
    disks:       ['local'],
    maxUploadMb: 2,
    ttl:         3600
);
echo "QUOTA TOKEN (max_upload=2MB):\n{$quotaToken}\n";
```

Chay:

```bash
php tests/generate-token.php
```

Luu cac token vao bien moi truong de dung cho cac buoc tiep theo:

```bash
export TOKEN="eyJhbGci..."
```

---

## 2. Test Authentication & Token

### 2.1 Request khong co token (expect 401)

```bash
curl -s http://localhost:8080/api/fm/list | jq .
# Expected: {"data":null,"error":"Missing authorization token"}
```

### 2.2 Token khong hop le (expect 401)

```bash
curl -s -H "Authorization: Bearer invalid-token-here" \
  http://localhost:8080/api/fm/list | jq .
# Expected: {"data":null,"error":"..."}
```

### 2.3 Token het han

```php
<?php
// Tao token het han (TTL = -1)
require_once __DIR__ . '/../embed.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$expiredToken = fluxfiles_token(userId: 'test', ttl: -1);
echo $expiredToken;
```

```bash
EXPIRED=$(php tests/expired-token.php)
curl -s -H "Authorization: Bearer $EXPIRED" \
  http://localhost:8080/api/fm/list | jq .
# Expected: 401 — Expired token
```

### 2.4 Token hop le — list files

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=" | jq .
# Expected: {"data":[...],"error":null}
```

### 2.5 Token khong co quyen truy cap disk

```bash
# Dung READ-ONLY TOKEN (chi co disk=local)
curl -s -H "Authorization: Bearer $READ_TOKEN" \
  "http://localhost:8080/api/fm/list?disk=s3&path=" | jq .
# Expected: 403 — Access denied to disk: s3
```

### 2.6 CORS Preflight

```bash
curl -s -X OPTIONS \
  -H "Origin: http://localhost:8080" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Authorization, Content-Type" \
  -i http://localhost:8080/api/fm/list
# Expected: 204 No Content, Access-Control-Allow-Origin header
```

### 2.7 CORS tu origin khong hop le

```bash
curl -s -X OPTIONS \
  -H "Origin: http://evil.com" \
  -i http://localhost:8080/api/fm/list
# Expected: KHONG co header Access-Control-Allow-Origin
```

---

## 3. Test File Operations (API)

### 3.1 List files

```bash
# List thu muc goc
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=" | jq .

# List thu muc con
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=photos" | jq .
```

### 3.2 Tao thu muc

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"test-folder"}' \
  http://localhost:8080/api/fm/mkdir | jq .
# Expected: {"data":{"created":true},"error":null}
```

### 3.3 Upload file

```bash
# Tao file test
echo "Hello FluxFiles" > /tmp/test.txt

# Upload
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" \
  -F "path=test-folder" \
  -F "file=@/tmp/test.txt" \
  http://localhost:8080/api/fm/upload | jq .
# Expected: {"data":{"key":"test-folder/test.txt","url":"...","size":16,...},"error":null}
```

### 3.4 Upload anh (kiem tra variants)

```bash
# Tao anh test 2000x1500
php -r "
\$img = imagecreatetruecolor(2000, 1500);
\$bg = imagecolorallocate(\$img, 100, 150, 200);
imagefill(\$img, 0, 0, \$bg);
imagejpeg(\$img, '/tmp/test-photo.jpg', 90);
imagedestroy(\$img);
echo 'Created test image 2000x1500' . PHP_EOL;
"

# Upload
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" \
  -F "path=" \
  -F "file=@/tmp/test-photo.jpg" \
  http://localhost:8080/api/fm/upload | jq .
# Expected: variants: { thumb: {key, width:150,...}, medium: {key, width:768,...}, large: {key, width:1920,...} }
```

### 3.5 Upload duplicate (MD5 check)

```bash
# Upload lai cung file
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" \
  -F "path=" \
  -F "file=@/tmp/test.txt" \
  http://localhost:8080/api/fm/upload | jq .
# Expected: {"data":{"duplicate":true,...},"error":null}

# Force upload
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" \
  -F "path=" \
  -F "file=@/tmp/test.txt" \
  -F "force_upload=1" \
  http://localhost:8080/api/fm/upload | jq .
# Expected: Upload thanh cong (overwrite)
```

### 3.6 Upload voi token gioi han extension

```bash
# Dung IMAGE-ONLY TOKEN, upload file .txt (expect loi)
curl -s -X POST \
  -H "Authorization: Bearer $IMAGE_TOKEN" \
  -F "disk=local" \
  -F "path=" \
  -F "file=@/tmp/test.txt" \
  http://localhost:8080/api/fm/upload | jq .
# Expected: 403 — Extension not allowed: txt
```

### 3.7 Upload voi token chi doc (expect loi)

```bash
curl -s -X POST \
  -H "Authorization: Bearer $READ_TOKEN" \
  -F "disk=local" \
  -F "path=" \
  -F "file=@/tmp/test.txt" \
  http://localhost:8080/api/fm/upload | jq .
# Expected: 403 — Permission denied: write
```

### 3.8 Di chuyen file

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","from":"test.txt","to":"test-folder/test-moved.txt"}' \
  http://localhost:8080/api/fm/move | jq .
# Expected: {"data":{"key":"test-folder/test-moved.txt"},"error":null}
```

### 3.9 Copy file

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","from":"test-folder/test-moved.txt","to":"test-copy.txt"}' \
  http://localhost:8080/api/fm/copy | jq .
```

### 3.10 Xoa file (soft delete)

```bash
curl -s -X DELETE \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"test-copy.txt"}' \
  http://localhost:8080/api/fm/delete | jq .
# Expected: {"data":{"trashed":true},"error":null}
```

### 3.11 Lay thong tin file

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/meta?disk=local&path=test-photo.jpg" | jq .
# Expected: {"data":{"size":...,"mime":"image/jpeg","modified":...},"error":null}
```

---

## 4. Test Metadata & SEO

### 4.1 Luu metadata

```bash
curl -s -X PUT \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "disk": "local",
    "key": "test-photo.jpg",
    "title": "Anh test FluxFiles",
    "alt_text": "Mot buc anh test mau xanh",
    "caption": "Day la caption cho buc anh",
    "tags": "test, fluxfiles, demo"
  }' \
  http://localhost:8080/api/fm/metadata | jq .
# Expected: {"data":{"saved":true},"error":null}
```

### 4.2 Doc metadata

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/metadata?disk=local&key=test-photo.jpg" | jq .
# Expected: {"data":{"title":"Anh test FluxFiles","alt_text":"...","caption":"...","tags":"..."},"error":null}
```

### 4.3 Xoa metadata

```bash
curl -s -X DELETE \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","key":"test-photo.jpg"}' \
  http://localhost:8080/api/fm/metadata | jq .
# Expected: {"data":{"deleted":true},"error":null}
```

---

## 5. Test Search (FTS5)

### 5.1 Chuan bi data search

```bash
# Luu metadata cho nhieu file de search
for i in 1 2 3; do
  # Tao file
  echo "content $i" > /tmp/doc-$i.txt
  curl -s -X POST -H "Authorization: Bearer $TOKEN" \
    -F "disk=local" -F "path=" -F "file=@/tmp/doc-$i.txt" \
    http://localhost:8080/api/fm/upload > /dev/null

  # Them metadata
  curl -s -X PUT -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"disk\":\"local\",\"key\":\"doc-$i.txt\",\"title\":\"Tai lieu so $i\",\"alt_text\":\"Document $i for testing\",\"tags\":\"document, test\"}" \
    http://localhost:8080/api/fm/metadata > /dev/null
done
echo "Da tao 3 file voi metadata"
```

### 5.2 Tim kiem

```bash
# Tim theo title
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/search?disk=local&q=tai+lieu&limit=10" | jq .

# Tim theo tags
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/search?disk=local&q=document&limit=10" | jq .

# Tim khong co ket qua
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/search?disk=local&q=khongtontai&limit=10" | jq .
# Expected: {"data":[],"error":null}
```

### 5.3 Kiem tra highlight

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/search?disk=local&q=test&limit=5" | jq '.data[0]'
# Expected: co cac truong title_hl, alt_hl, caption_hl, tags_hl voi <mark> tag
```

---

## 6. Test Trash (Soft Delete)

### 6.1 Xoa file vao thung rac

```bash
curl -s -X DELETE \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"doc-1.txt"}' \
  http://localhost:8080/api/fm/delete | jq .
# Expected: {"data":{"trashed":true},"error":null}
```

### 6.2 Liet ke thung rac

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/trash?disk=local" | jq .
# Expected: co file doc-1.txt trong danh sach, co trashed_at timestamp
```

### 6.3 Khoi phuc file

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"doc-1.txt"}' \
  http://localhost:8080/api/fm/restore | jq .
# Expected: {"data":{"restored":true},"error":null}
```

### 6.4 Xoa vinh vien

```bash
# Xoa lai vao thung rac truoc
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"doc-1.txt"}' \
  http://localhost:8080/api/fm/delete > /dev/null

# Purge vinh vien
curl -s -X DELETE \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"doc-1.txt"}' \
  http://localhost:8080/api/fm/purge | jq .
# Expected: {"data":{"purged":true},"error":null}

# Verify — list trash khong con file nay
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/trash?disk=local" | jq '.data | length'
```

---

## 7. Test Image Optimization & Crop

### 7.1 Upload anh va kiem tra variants

```bash
# Tao anh lon 2000x1500
php -r "
\$img = imagecreatetruecolor(2000, 1500);
\$red = imagecolorallocate(\$img, 220, 50, 50);
imagefill(\$img, 0, 0, \$red);
imagejpeg(\$img, '/tmp/red-photo.jpg', 90);
imagedestroy(\$img);
"

curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" -F "path=" -F "file=@/tmp/red-photo.jpg" \
  http://localhost:8080/api/fm/upload | jq '.data.variants'
# Expected:
# {
#   "thumb":  { "key": "_variants/red-photo_thumb.webp",  "width": 150,  "height": ... },
#   "medium": { "key": "_variants/red-photo_medium.webp", "width": 768,  "height": ... },
#   "large":  { "key": "_variants/red-photo_large.webp",  "width": 1920, "height": ... }
# }
```

### 7.2 Upload anh nho (khong tao variant lon)

```bash
# Anh 100x100 — chi tao thumb
php -r "
\$img = imagecreatetruecolor(100, 100);
imagejpeg(\$img, '/tmp/tiny.jpg');
imagedestroy(\$img);
"

curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" -F "path=" -F "file=@/tmp/tiny.jpg" \
  http://localhost:8080/api/fm/upload | jq '.data.variants'
# Expected: chi co "thumb", khong co "medium" va "large"
```

### 7.3 Crop anh

```bash
# Crop vung 100x100 bat dau tu (50, 50)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "disk": "local",
    "path": "red-photo.jpg",
    "x": 50, "y": 50,
    "width": 800, "height": 600
  }' \
  http://localhost:8080/api/fm/crop | jq .
# Expected: {"data":{"key":"red-photo.jpg","url":"...","width":800,"height":600,"variants":{...}},"error":null}
```

### 7.4 Crop va luu thanh file moi

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "disk": "local",
    "path": "red-photo.jpg",
    "x": 0, "y": 0,
    "width": 500, "height": 500,
    "save_path": "red-photo-cropped.jpg"
  }' \
  http://localhost:8080/api/fm/crop | jq .
# Expected: key la "red-photo-cropped.jpg", file goc khong bi thay doi
```

---

## 8. Test AI Auto-Tag

> Yeu cau: Phai cau hinh `FLUXFILES_AI_PROVIDER` va `FLUXFILES_AI_API_KEY` trong `.env`

### 8.1 Tag anh thu cong

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"red-photo.jpg"}' \
  http://localhost:8080/api/fm/ai-tag | jq .
# Expected: {"data":{"tags":["red","solid color",...],"title":"...","alt_text":"...","caption":"..."},"error":null}
```

### 8.2 AI tag khi chua cau hinh provider (expect loi)

```bash
# Tam thoi xoa FLUXFILES_AI_PROVIDER trong .env, restart server
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"red-photo.jpg"}' \
  http://localhost:8080/api/fm/ai-tag | jq .
# Expected: 400 — AI tagging is not configured
```

### 8.3 AI tag file khong phai anh (expect loi)

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"test-folder/test-moved.txt"}' \
  http://localhost:8080/api/fm/ai-tag | jq .
# Expected: 400 — Not an image
```

### 8.4 AI tag giu metadata da co

```bash
# Luu metadata truoc
curl -s -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","key":"red-photo.jpg","title":"My Custom Title","alt_text":"","caption":""}' \
  http://localhost:8080/api/fm/metadata > /dev/null

# Chay AI tag
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"red-photo.jpg"}' \
  http://localhost:8080/api/fm/ai-tag | jq .
# Expected: title van la "My Custom Title" (khong bi ghi de), alt_text va caption duoc AI dien vao
```

---

## 9. Test Cross-Disk Copy/Move

> Yeu cau: Cau hinh it nhat 2 disk (vd: `local` va `s3`)

### 9.1 Copy tu local sang S3

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "src_disk": "local",
    "src_path": "red-photo.jpg",
    "dst_disk": "s3",
    "dst_path": "backups/red-photo.jpg"
  }' \
  http://localhost:8080/api/fm/cross-copy | jq .
# Expected: {"data":{"key":"backups/red-photo.jpg","url":"...","src_disk":"local","dst_disk":"s3"},"error":null}
```

### 9.2 Move tu local sang S3

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "src_disk": "local",
    "src_path": "doc-2.txt",
    "dst_disk": "s3",
    "dst_path": "archive/doc-2.txt"
  }' \
  http://localhost:8080/api/fm/cross-move | jq .
# Expected: File da duoc chuyen, khong con tren local

# Verify khong con tren local
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=" | jq '.data[] | select(.basename=="doc-2.txt")'
# Expected: khong tra ve gi (file da bi di chuyen)
```

### 9.3 Cross-disk khi khong co quyen disk dich (expect loi)

```bash
# Dung READ-ONLY TOKEN (chi co disk=local)
curl -s -X POST \
  -H "Authorization: Bearer $READ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"src_disk":"local","src_path":"test.txt","dst_disk":"s3","dst_path":"test.txt"}' \
  http://localhost:8080/api/fm/cross-copy | jq .
# Expected: 403 — Access denied to disk: s3
```

---

## 10. Test Chunk Upload (S3 Multipart)

> Yeu cau: Cau hinh S3 hoac R2 trong `.env`

### 10.1 Initiate multipart upload

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"s3","path":"large-file.bin"}' \
  http://localhost:8080/api/fm/chunk/init | jq .
# Expected: {"data":{"upload_id":"...","key":"large-file.bin","chunk_size":5242880},"error":null}
```

### 10.2 Lay presigned URL cho tung part

```bash
UPLOAD_ID="abc123"  # Thay bang upload_id tu buoc tren

curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"disk\":\"s3\",\"key\":\"large-file.bin\",\"upload_id\":\"$UPLOAD_ID\",\"part_number\":1}" \
  http://localhost:8080/api/fm/chunk/presign | jq .
# Expected: {"data":{"url":"https://s3...","part_number":1,"expires_at":...},"error":null}
```

### 10.3 Complete multipart

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"disk\": \"s3\",
    \"key\": \"large-file.bin\",
    \"upload_id\": \"$UPLOAD_ID\",
    \"parts\": [
      {\"PartNumber\": 1, \"ETag\": \"etag-part-1\"},
      {\"PartNumber\": 2, \"ETag\": \"etag-part-2\"}
    ]
  }" \
  http://localhost:8080/api/fm/chunk/complete | jq .
```

### 10.4 Abort multipart

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"disk\":\"s3\",\"key\":\"large-file.bin\",\"upload_id\":\"$UPLOAD_ID\"}" \
  http://localhost:8080/api/fm/chunk/abort | jq .
# Expected: {"data":{"aborted":true},"error":null}
```

---

## 11. Test Rate Limiting

### 11.1 Vuot gioi han write (10 req/phut)

```bash
# Gui 11 request write lien tiep
for i in $(seq 1 11); do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"disk":"local","path":"test-folder"}' \
    http://localhost:8080/api/fm/mkdir)
  echo "Request $i: HTTP $STATUS"
done
# Expected: Request 1-10: HTTP 200, Request 11: HTTP 429
```

### 11.2 Vuot gioi han read (60 req/phut)

```bash
for i in $(seq 1 62); do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer $TOKEN" \
    "http://localhost:8080/api/fm/list?disk=local&path=")
  if [ "$STATUS" = "429" ]; then
    echo "Rate limited at request $i"
    break
  fi
done
# Expected: Rate limited at request 61 hoac 62
```

### 11.3 Kiem tra response 429

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=" | jq .
# Neu bi rate limit: {"data":null,"error":"Rate limit exceeded..."}
```

---

## 12. Test Quota

### 12.1 Xem quota hien tai

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/quota?disk=local" | jq .
# Expected: {"data":{"used_bytes":...,"used_mb":...,"max_mb":0,"remaining_mb":...,"percentage":...},"error":null}
# max_mb=0 nghia la unlimited
```

### 12.2 Test vuot quota

```bash
# Dung QUOTA TOKEN (max_upload=2MB)
# Tao file 3MB
dd if=/dev/urandom of=/tmp/big-file.bin bs=1M count=3 2>/dev/null

curl -s -X POST \
  -H "Authorization: Bearer $QUOTA_TOKEN" \
  -F "disk=local" -F "path=" -F "file=@/tmp/big-file.bin" \
  http://localhost:8080/api/fm/upload | jq .
# Expected: 413 — File is too large
```

---

## 13. Test Audit Log

### 13.1 Xem audit log

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/audit?limit=20&offset=0" | jq .
# Expected: Danh sach cac hanh dong: upload, delete, move, copy, mkdir, restore, purge, ...
```

### 13.2 Loc theo user

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/audit?limit=10&user_id=test-user-001" | jq .
```

### 13.3 Phan trang

```bash
# Trang 1
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/audit?limit=5&offset=0" | jq '.data | length'

# Trang 2
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/audit?limit=5&offset=5" | jq '.data | length'
```

---

## 14. Test Internationalization (i18n)

### 14.1 Chay test script tu dong

```bash
# Test tat ca 16 ngon ngu
php tests/test-i18n.php

# Test rieng 1 ngon ngu
php tests/test-i18n.php vi
php tests/test-i18n.php ar
php tests/test-i18n.php th

# Test kem API (can server dang chay)
php tests/test-i18n.php --api
```

### 14.2 Test API — liet ke ngon ngu

```bash
curl -s http://localhost:8080/api/fm/lang | jq .
# Expected: 16 locales voi code, name, dir
```

### 14.3 Test API — lay ban dich tung ngon ngu

```bash
# Tieng Viet
curl -s http://localhost:8080/api/fm/lang/vi | jq '.data.messages.upload.drop_hint'
# Expected: "Tha file vao day hoac nhan de tai len"

# Tieng Nhat
curl -s http://localhost:8080/api/fm/lang/ja | jq '.data.messages.upload.drop_hint'
# Expected: Chuoi tieng Nhat

# Arabic (RTL)
curl -s http://localhost:8080/api/fm/lang/ar | jq '.data.dir'
# Expected: "rtl"

# Thai
curl -s http://localhost:8080/api/fm/lang/th | jq '.data.messages.common'
# Expected: Cac chuoi tieng Thai

# Hindi
curl -s http://localhost:8080/api/fm/lang/hi | jq '.data.messages.common.save'
# Expected: Chuoi tieng Hindi
```

### 14.4 Test locale khong ton tai

```bash
curl -s http://localhost:8080/api/fm/lang/xx | jq .
# Expected: 404 — {"data":null,"error":"Locale not found"}
```

### 14.5 Test I18n class truc tiep

```bash
# Test variable interpolation
php -r "
require 'vendor/autoload.php';
\$i = new FluxFiles\I18n('lang', 'vi');
echo \$i->t('file.items', ['count' => 42]) . PHP_EOL;
echo \$i->t('upload.quota_exceeded', ['used' => '8.5GB', 'max' => '10GB']) . PHP_EOL;
echo \$i->t('error.ext_not_allowed', ['ext' => 'exe']) . PHP_EOL;
echo \$i->t('delete.confirm_file', ['name' => 'photo.jpg']) . PHP_EOL;
"

# Test fallback (key khong ton tai tra ve key)
php -r "
require 'vendor/autoload.php';
\$i = new FluxFiles\I18n('lang', 'vi');
echo \$i->t('khong.ton.tai') . PHP_EOL;
"
# Expected: "khong.ton.tai" (tra ve chinh key)

# Test Accept-Language header detection
php -r "
\$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ja,en-US;q=0.9,en;q=0.8';
require 'vendor/autoload.php';
\$i = new FluxFiles\I18n('lang');
echo 'Detected: ' . \$i->locale() . PHP_EOL;
"
# Expected: "ja"

# Test forced locale override
php -r "
\$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ja';
\$_GET['lang'] = 'ko';
require 'vendor/autoload.php';
\$i = new FluxFiles\I18n('lang', 'vi');
echo 'Forced: ' . \$i->locale() . PHP_EOL;
"
# Expected: "vi" (force > URL param > header)
```

### 14.6 Test chuyen ngon ngu tren trinh duyet

Mo trinh duyet:

```
http://localhost:8080/public/index.html?lang=vi
http://localhost:8080/public/index.html?lang=ar
http://localhost:8080/public/index.html?lang=ja
http://localhost:8080/public/index.html?lang=th
http://localhost:8080/public/index.html?lang=hi
http://localhost:8080/public/index.html?lang=ru
```

**Kiem tra:**
- [ ] Tat ca text UI chuyen sang ngon ngu tuong ung
- [ ] Arabic: layout chuyen sang RTL (sidebar ben phai, text can phai)
- [ ] Placeholder trong o search chuyen ngon ngu
- [ ] Tooltip cua cac nut chuyen ngon ngu
- [ ] Dialog xac nhan xoa hien thi ngon ngu dung

### 14.7 Test chuyen ngon ngu qua SDK

```html
<script src="http://localhost:8080/fluxfiles.js"></script>
<script>
FluxFiles.open({
    endpoint: 'http://localhost:8080',
    token: 'YOUR_TOKEN',
    locale: 'ar',  // Thu doi: vi, ja, ko, fr, de, es, pt, zh, th, hi, tr, nl, ru, it
    disk: 'local'
});
</script>
```

---

## 15. Test Frontend UI

Mo `http://localhost:8080/public/index.html` tren trinh duyet.

### 15.1 Navigation

- [ ] Click vao thu muc de di chuyen
- [ ] Breadcrumb hoat dong (click de quay lai thu muc cha)
- [ ] Nut "Root" / quay ve thu muc goc
- [ ] Sidebar hien thi cay thu muc

### 15.2 Upload

- [ ] Keo tha file vao dropzone
- [ ] Click dropzone de chon file
- [ ] Hien thi progress bar khi upload
- [ ] Upload nhieu file cung luc
- [ ] Thong bao loi khi file qua lon
- [ ] Thong bao duplicate khi upload file da ton tai

### 15.3 File Operations

- [ ] Click chon file — hien thi detail panel
- [ ] Double-click mo thu muc
- [ ] Copy URL — clipboard
- [ ] Xoa file — dialog xac nhan
- [ ] Di chuyen file — dialog chon thu muc dich
- [ ] Tao thu muc moi — prompt ten

### 15.4 View Modes

- [ ] Chuyen grid view / list view
- [ ] Grid: hien thi icon + ten file
- [ ] List: hien thi ten, kich thuoc, ngay chinh sua

### 15.5 Multi-select & Bulk Operations

- [ ] Ctrl+Click chon nhieu file
- [ ] Shift+Click chon dai file
- [ ] Select All checkbox
- [ ] Bulk bar hien thi so file da chon
- [ ] Bulk delete — xoa nhieu file
- [ ] Bulk move — di chuyen nhieu file
- [ ] Bulk download — tai nhieu file

### 15.6 Detail Panel

- [ ] Tab Info: hien thi ten, kich thuoc, loai, ngay sua, disk, duong dan
- [ ] Tab SEO: chinh sua title, alt text, caption — auto-save sau 800ms
- [ ] Tab AI Tags: nut "Generate AI Tags", hien thi tag pills, xoa tag
- [ ] Tab Crop: crop anh voi cac ty le (Free, 1:1, 4:3, 16:9, 3:2)
- [ ] Image preview hien thi trong panel
- [ ] Video/audio preview voi controls
- [ ] PDF preview inline

### 15.7 Disk Switcher

- [ ] Hien thi cac disk (Local, S3, R2)
- [ ] Click chuyen disk — load lai file list
- [ ] Disk active duoc highlight

### 15.8 Search

- [ ] Go vao o search — loc file theo ten
- [ ] Full-text search qua API (title, alt_text, caption, tags)
- [ ] Hien thi ket qua voi highlight

### 15.9 Trash

- [ ] Click "Thung rac" trong sidebar
- [ ] Hien thi danh sach file da xoa
- [ ] Restore file tu thung rac
- [ ] Purge file vinh vien
- [ ] Thung rac trong — hien thi thong bao

### 15.10 Dark Mode

- [ ] Tu dong theo system preference
- [ ] Chuyen doi light/dark — mau sac cap nhat dung

### 15.11 Presigned URL

- [ ] Click "Presigned URL" tren file S3/R2
- [ ] Chon thoi gian het han
- [ ] Generate link — copy vao clipboard

---

## 16. Test SDK (fluxfiles.js)

Tao file `tests/test-sdk.html`:

```html
<!DOCTYPE html>
<html>
<head>
    <title>FluxFiles SDK Test</title>
</head>
<body>
    <h1>FluxFiles SDK Test</h1>

    <div>
        <button onclick="openPicker()">Open Picker (Modal)</button>
        <button onclick="openBrowser()">Open Browser (Embedded)</button>
        <button onclick="testLocale('vi')">Open - Tieng Viet</button>
        <button onclick="testLocale('ar')">Open - Arabic (RTL)</button>
        <button onclick="testLocale('ja')">Open - Japanese</button>
        <button onclick="testLocale('th')">Open - Thai</button>
    </div>

    <div>
        <h3>Commands:</h3>
        <button onclick="FluxFiles.navigate('/test-folder')">Navigate to test-folder</button>
        <button onclick="FluxFiles.setDisk('s3')">Switch to S3</button>
        <button onclick="FluxFiles.refresh()">Refresh</button>
        <button onclick="FluxFiles.search('photo')">Search "photo"</button>
        <button onclick="FluxFiles.aiTag()">AI Tag</button>
        <button onclick="FluxFiles.close()">Close</button>
    </div>

    <div id="embedded" style="width:100%;height:500px;border:1px solid #ccc;margin-top:20px;display:none;"></div>

    <h3>Event Log:</h3>
    <pre id="log" style="background:#f5f5f5;padding:12px;max-height:300px;overflow:auto;"></pre>

    <script src="http://localhost:8080/fluxfiles.js"></script>
    <script>
        var TOKEN = 'YOUR_TOKEN_HERE';  // Thay bang token tu generate-token.php
        var log = document.getElementById('log');

        function addLog(msg) {
            log.textContent = new Date().toLocaleTimeString() + ' — ' + msg + '\n' + log.textContent;
        }

        FluxFiles.on('FM_READY', function(p) { addLog('FM_READY: ' + JSON.stringify(p)); });
        FluxFiles.on('FM_SELECT', function(f) { addLog('FM_SELECT: ' + f.basename + ' (' + f.url + ')'); });
        FluxFiles.on('FM_EVENT', function(e) { addLog('FM_EVENT: ' + e.action + ' — ' + JSON.stringify(e)); });
        FluxFiles.on('FM_CLOSE', function() { addLog('FM_CLOSE'); });

        function openPicker() {
            FluxFiles.open({
                endpoint: 'http://localhost:8080',
                token: TOKEN,
                disk: 'local',
                mode: 'picker',
                onSelect: function(file) {
                    addLog('onSelect callback: ' + file.basename);
                }
            });
        }

        function openBrowser() {
            document.getElementById('embedded').style.display = 'block';
            FluxFiles.open({
                endpoint: 'http://localhost:8080',
                token: TOKEN,
                disk: 'local',
                mode: 'browser',
                container: '#embedded'
            });
        }

        function testLocale(locale) {
            FluxFiles.open({
                endpoint: 'http://localhost:8080',
                token: TOKEN,
                disk: 'local',
                mode: 'browser',
                locale: locale
            });
        }
    </script>
</body>
</html>
```

**Cach test:**

```bash
# Mo file test (can server dang chay)
open http://localhost:8080/tests/test-sdk.html
# Hoac dung trinh duyet mo URL tren
```

**Kiem tra:**
- [ ] Open Picker — mo modal overlay, chon file tra ve event
- [ ] Open Browser — embed trong div
- [ ] Cac nut locale — UI chuyen ngon ngu
- [ ] Navigate — di chuyen den thu muc
- [ ] Switch disk — chuyen disk
- [ ] Refresh — tai lai danh sach
- [ ] Search — tim kiem
- [ ] Close — dong file manager
- [ ] Event log ghi nhan tat ca events

---

## 17. Test React Adapter

### 17.1 Setup

```bash
cd adapters/react
npm install
npm run build
```

### 17.2 Test TypeScript types

```bash
npx tsc --noEmit
# Expected: khong co loi
```

### 17.3 Test trong app React

```tsx
import { FluxFilesModal } from '@fluxfiles/react';
import { useState } from 'react';

function App() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button onClick={() => setOpen(true)}>Open FluxFiles</button>
            <FluxFilesModal
                open={open}
                endpoint="http://localhost:8080"
                token="YOUR_TOKEN"
                disk="local"
                locale="vi"
                onSelect={(file) => {
                    console.log('Selected:', file);
                    setOpen(false);
                }}
                onClose={() => setOpen(false)}
                onReady={() => console.log('Ready!')}
                onEvent={(e) => console.log('Event:', e)}
            />
        </>
    );
}
```

**Kiem tra:**
- [ ] Modal mo/dong dung
- [ ] onSelect tra ve FluxFile object
- [ ] onReady goi khi iframe san sang
- [ ] onEvent ghi nhan upload/delete/move events
- [ ] locale prop chuyen ngon ngu

---

## 18. Test Laravel Adapter

### 18.1 Setup

```bash
# Trong project Laravel
composer require fluxfiles/laravel
php artisan vendor:publish --tag=fluxfiles-config
```

Sua `config/fluxfiles.php`:
```php
'secret' => env('FLUXFILES_SECRET', 'your-secret-key'),
'locale' => env('FLUXFILES_LOCALE', ''),
```

### 18.2 Test routes

```bash
# Lang routes (public)
curl -s http://localhost:8000/api/fm/lang | jq .
curl -s http://localhost:8000/api/fm/lang/vi | jq .

# File list (can auth)
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/fm/list?disk=local | jq .
```

### 18.3 Test Blade component

```blade
{{-- resources/views/files.blade.php --}}
<x-fluxfiles
    disk="local"
    mode="browser"
    locale="vi"
    width="100%"
    height="600px"
/>
```

### 18.4 Test Facade

```php
use FluxFiles\Laravel\FluxFilesFacade as FluxFiles;

$token = FluxFiles::token(
    userId: auth()->id(),
    perms: ['read', 'write'],
    disks: ['local']
);

// Verify token co the dung
$response = Http::withToken($token)
    ->get(config('fluxfiles.endpoint') . '/api/fm/list', ['disk' => 'local']);

assert($response->ok());
```

---

## 19. Test WordPress Adapter

### 19.1 Setup

1. Copy `adapters/wordpress/` vao `wp-content/plugins/fluxfiles/`
2. Kich hoat plugin tai WP Admin > Plugins
3. Cau hinh tai Settings > FluxFiles

### 19.2 Test Shortcode

Tao page moi, them shortcode:

```
[fluxfiles disk="local" mode="browser" height="600px"]
```

**Kiem tra:**
- [ ] File manager hien thi trong page
- [ ] Ngon ngu theo WordPress locale (Settings > General > Site Language)

### 19.3 Test Media Button

1. Vao Posts > Add New (Classic Editor)
2. Click nut "FluxFiles" tren toolbar
3. **Kiem tra:**
   - [ ] Modal mo ra voi file manager
   - [ ] Chon anh — tu dong insert `<img>` tag vao editor
   - [ ] Chon file khac — insert `<a>` tag
   - [ ] Dong modal bang X hoac click overlay

### 19.4 Test REST API

```bash
# Lay nonce tu WP
curl -s -c cookies.txt http://localhost/wp-login.php \
  -d "log=admin&pwd=password&wp-submit=Log+In"

curl -s -b cookies.txt \
  -H "X-WP-Nonce: $(curl -s -b cookies.txt http://localhost/wp-json/ | jq -r '.authentication.cookie.nonce')" \
  http://localhost/wp-json/fluxfiles/v1/list?disk=local | jq .
```

---

## 20. Test Security

### 20.1 Path traversal

```bash
# Thu truy cap file ngoai pham vi
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=../../etc" | jq .
# Expected: Duong dan bi normalize, khong thoat khoi root

curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","from":"../../../etc/passwd","to":"stolen.txt"}' \
  http://localhost:8080/api/fm/copy | jq .
# Expected: Bi chan hoac file khong ton tai
```

### 20.2 Token scope (path prefix)

```bash
# Dung SCOPED TOKEN (prefix=users/scoped-user/)
# Thu list thu muc goc
curl -s -H "Authorization: Bearer $SCOPED_TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=" | jq .
# Expected: Chi thay file trong users/scoped-user/

# Thu truy cap ngoai prefix
curl -s -H "Authorization: Bearer $SCOPED_TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=other-user" | jq .
# Expected: Bi chan hoac tra ve rong
```

### 20.3 Permission enforcement

```bash
# Token chi co quyen read — thu write
curl -s -X POST -H "Authorization: Bearer $READ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"hack"}' \
  http://localhost:8080/api/fm/mkdir | jq .
# Expected: 403 — Permission denied: write

# Token chi co quyen read — thu delete
curl -s -X DELETE -H "Authorization: Bearer $READ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"test.txt"}' \
  http://localhost:8080/api/fm/delete | jq .
# Expected: 403 — Permission denied: delete
```

### 20.4 FLUXFILES_SECRET chua cau hinh

```bash
# Tam thoi set FLUXFILES_SECRET=change-me-to-random-32-char-string trong .env
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/api/fm/list?disk=local&path=" | jq .
# Expected: 500 — FLUXFILES_SECRET is not configured
```

### 20.5 Request body validation

```bash
# Thieu truong bat buoc
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}' \
  http://localhost:8080/api/fm/move | jq .
# Expected: 400 — Missing required field: disk

# JSON khong hop le
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d 'not-json' \
  http://localhost:8080/api/fm/move | jq .
# Expected: 400 — Invalid JSON body
```

---

## 21. Test Script Tu dong

### 21.1 Test i18n (da co san)

```bash
php tests/test-i18n.php          # Tat ca ngon ngu
php tests/test-i18n.php vi       # Chi tieng Viet
php tests/test-i18n.php --api    # Kem test API (can server)
```

### 21.2 Test API toan dien

Tao file `tests/test-api.sh`:

```bash
#!/bin/bash
set -e

BASE="http://localhost:8080/api/fm"
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Generate token
TOKEN=$(php -r "
require 'embed.php';
\$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
\$dotenv->safeLoad();
echo fluxfiles_token('test-api', ['read','write','delete'], ['local'], '', 50, null, 86400);
")

PASS=0
FAIL=0

check() {
    local desc="$1"
    local expected_code="$2"
    local actual_code="$3"
    local body="$4"

    if [ "$actual_code" = "$expected_code" ]; then
        echo -e "  ${GREEN}✓${NC} $desc (HTTP $actual_code)"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}✗${NC} $desc — expected $expected_code, got $actual_code"
        echo "    Response: $body"
        FAIL=$((FAIL + 1))
    fi
}

echo -e "\n${YELLOW}═══ FluxFiles API Test Suite ═══${NC}\n"

# --- Auth ---
echo -e "${YELLOW}[Auth]${NC}"

RESP=$(curl -s -w "\n%{http_code}" "$BASE/list")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -1)
check "No token → 401" "401" "$CODE" "$BODY"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer bad" "$BASE/list")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -1)
check "Invalid token → 401" "401" "$CODE" "$BODY"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/list?disk=local&path=")
CODE=$(echo "$RESP" | tail -1)
check "Valid token → 200" "200" "$CODE"

# --- Lang (public) ---
echo -e "\n${YELLOW}[i18n]${NC}"

RESP=$(curl -s -w "\n%{http_code}" "$BASE/lang")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -1)
COUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']))" 2>/dev/null || echo 0)
check "GET /lang → 200 ($COUNT locales)" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" "$BASE/lang/vi")
CODE=$(echo "$RESP" | tail -1)
check "GET /lang/vi → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" "$BASE/lang/xx")
CODE=$(echo "$RESP" | tail -1)
check "GET /lang/xx → 404" "404" "$CODE"

# --- Mkdir ---
echo -e "\n${YELLOW}[Mkdir]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"disk":"local","path":"api-test-dir"}' "$BASE/mkdir")
CODE=$(echo "$RESP" | tail -1)
check "Create directory → 200" "200" "$CODE"

# --- Upload ---
echo -e "\n${YELLOW}[Upload]${NC}"

echo "test content" > /tmp/ff-test-upload.txt
RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" -F "path=api-test-dir" -F "file=@/tmp/ff-test-upload.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
check "Upload file → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -F "disk=local" -F "path=api-test-dir" -F "file=@/tmp/ff-test-upload.txt" "$BASE/upload")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -1)
DUP=$(echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'].get('duplicate',False))" 2>/dev/null || echo "")
check "Duplicate detection" "200" "$CODE"

# --- List ---
echo -e "\n${YELLOW}[List]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/list?disk=local&path=api-test-dir")
CODE=$(echo "$RESP" | tail -1)
check "List directory → 200" "200" "$CODE"

# --- Metadata ---
echo -e "\n${YELLOW}[Metadata]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","key":"api-test-dir/ff-test-upload.txt","title":"Test Title","alt_text":"Alt","caption":"Cap","tags":"test,api"}' \
  "$BASE/metadata")
CODE=$(echo "$RESP" | tail -1)
check "Save metadata → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$BASE/metadata?disk=local&key=api-test-dir/ff-test-upload.txt")
CODE=$(echo "$RESP" | tail -1)
check "Get metadata → 200" "200" "$CODE"

# --- Search ---
echo -e "\n${YELLOW}[Search]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/search?disk=local&q=Test+Title&limit=5")
CODE=$(echo "$RESP" | tail -1)
check "Search → 200" "200" "$CODE"

# --- Move & Copy ---
echo -e "\n${YELLOW}[Move & Copy]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","from":"api-test-dir/ff-test-upload.txt","to":"api-test-dir/copied.txt"}' \
  "$BASE/copy")
CODE=$(echo "$RESP" | tail -1)
check "Copy file → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","from":"api-test-dir/copied.txt","to":"api-test-dir/moved.txt"}' \
  "$BASE/move")
CODE=$(echo "$RESP" | tail -1)
check "Move file → 200" "200" "$CODE"

# --- Trash ---
echo -e "\n${YELLOW}[Trash]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"api-test-dir/moved.txt"}' "$BASE/delete")
CODE=$(echo "$RESP" | tail -1)
check "Soft delete → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/trash?disk=local")
CODE=$(echo "$RESP" | tail -1)
check "List trash → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"api-test-dir/moved.txt"}' "$BASE/restore")
CODE=$(echo "$RESP" | tail -1)
check "Restore → 200" "200" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"api-test-dir/moved.txt"}' "$BASE/delete")
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"api-test-dir/moved.txt"}' "$BASE/purge" > /dev/null 2>&1
CODE=$(echo "$RESP" | tail -1)
check "Delete + Purge → 200" "200" "$CODE"

# --- Quota ---
echo -e "\n${YELLOW}[Quota]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/quota?disk=local")
CODE=$(echo "$RESP" | tail -1)
check "Get quota → 200" "200" "$CODE"

# --- Audit ---
echo -e "\n${YELLOW}[Audit]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/audit?limit=10")
CODE=$(echo "$RESP" | tail -1)
check "Audit log → 200" "200" "$CODE"

# --- Validation ---
echo -e "\n${YELLOW}[Validation]${NC}"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{}' "$BASE/move")
CODE=$(echo "$RESP" | tail -1)
check "Missing fields → 400" "400" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d 'invalid' "$BASE/move")
CODE=$(echo "$RESP" | tail -1)
check "Invalid JSON → 400" "400" "$CODE"

RESP=$(curl -s -w "\n%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE/nonexistent")
CODE=$(echo "$RESP" | tail -1)
check "Unknown route → 404" "404" "$CODE"

# --- Cleanup ---
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"api-test-dir/ff-test-upload.txt"}' "$BASE/delete" > /dev/null 2>&1
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"disk":"local","path":"api-test-dir/ff-test-upload.txt"}' "$BASE/purge" > /dev/null 2>&1
rm -f /tmp/ff-test-upload.txt

# --- Summary ---
echo -e "\n${YELLOW}═══════════════════════════════════${NC}"
TOTAL=$((PASS + FAIL))
if [ $FAIL -eq 0 ]; then
    echo -e "${GREEN}All $TOTAL tests passed!${NC}"
else
    echo -e "${RED}$FAIL/$TOTAL tests failed${NC}"
fi
echo ""
exit $FAIL
```

Chay:

```bash
chmod +x tests/test-api.sh
bash tests/test-api.sh
```

### 21.3 Test tat ca cung luc

```bash
# 1. Khoi dong server
php -S localhost:8080 -t . &
SERVER_PID=$!
sleep 1

# 2. Chay i18n tests
echo "=== i18n Tests ==="
php tests/test-i18n.php --api

# 3. Chay API tests
echo "=== API Tests ==="
bash tests/test-api.sh

# 4. Tat server
kill $SERVER_PID
```

---

## Checklist Tong hop

| # | Tinh nang | Cach test | Ket qua |
|---|-----------|-----------|---------|
| 1 | JWT Auth | `curl` khong token / token sai / token het han | 401 |
| 2 | CORS | `OPTIONS` request voi origin hop le / khong hop le | 204 / khong co header |
| 3 | List files | `GET /list` | 200 + mang file |
| 4 | Upload | `POST /upload` multipart | 200 + file info |
| 5 | Upload anh + variants | Upload JPG > 768px | 200 + variants object |
| 6 | Duplicate detect | Upload cung file 2 lan | duplicate: true |
| 7 | Mkdir | `POST /mkdir` | created: true |
| 8 | Move | `POST /move` | 200 + key moi |
| 9 | Copy | `POST /copy` | 200 + key moi |
| 10 | Delete (soft) | `DELETE /delete` | trashed: true |
| 11 | Trash list | `GET /trash` | Mang file da xoa |
| 12 | Restore | `POST /restore` | restored: true |
| 13 | Purge | `DELETE /purge` | purged: true |
| 14 | Metadata CRUD | `PUT/GET/DELETE /metadata` | saved/data/deleted |
| 15 | Search FTS5 | `GET /search?q=` | Ket qua voi highlight |
| 16 | Image crop | `POST /crop` | 200 + kich thuoc moi |
| 17 | AI tag | `POST /ai-tag` | tags + title + alt + caption |
| 18 | Cross-disk copy | `POST /cross-copy` | 200 + dst key |
| 19 | Cross-disk move | `POST /cross-move` | 200 + file da di chuyen |
| 20 | Chunk upload | init → presign → complete/abort | upload_id, url, completed |
| 21 | Rate limit | 11 write requests lien tiep | Request 11 → 429 |
| 22 | Quota | Upload file lon hon max | 413 |
| 23 | Audit log | `GET /audit` | Danh sach hanh dong |
| 24 | i18n API | `GET /lang`, `GET /lang/{code}` | 16 locales, translations |
| 25 | i18n class | `php tests/test-i18n.php` | All passed |
| 26 | Permission | Token read-only thu write | 403 |
| 27 | Disk access | Token local thu truy cap s3 | 403 |
| 28 | Path traversal | `../../etc/passwd` | Bi chan |
| 29 | Extension filter | Upload .exe voi token chi cho .jpg | 403 |
| 30 | Frontend UI | Mo trinh duyet test tat ca tab | Visual check |
| 31 | SDK | `test-sdk.html` — open/close/commands/events | Events logged |
| 32 | Dark mode | Chuyen doi theme | Mau sac cap nhat |
| 33 | RTL | `locale=ar` | Layout dao nguoc |
| 34 | React adapter | `npm run build` + mount component | Render dung |
| 35 | Laravel adapter | `composer require` + routes + Blade | Hoat dong |
| 36 | WordPress adapter | Plugin activate + shortcode + media button | Hoat dong |
