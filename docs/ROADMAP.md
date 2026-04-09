# 🚀 FluxFiles ROADMAP (v1.26.2 → v2.0)

> ⚠️ IMPORTANT
> This roadmap is designed for:
>
> - BYOB (Bring Your Own Bucket)
> - Stateless architecture
> - JSON metadata (NO central database)
>
> Server = coordinator only
> Storage + metadata = owned by user

---

# 🎯 PRODUCT GOAL

Build FluxFiles into:

> 🔥 Stateless Upload & Media System (BYOB-first)

---

# 🧠 CORE PRINCIPLE

```text
Presign → Direct Upload → Complete → JSON Metadata
```

---

# 🚨 PHASE 1 — CORE (BUILD FAST)

## 🎯 Goal

Make upload work end-to-end (usable ASAP)

---

## 🥇 STEP 1 — Presigned Upload API

### Endpoint

```
POST /api/presign
```

---

### Input

```json
{
  "filename": "image.jpg",
  "mime": "image/jpeg",
  "size": 123456
}
```

---

### Logic

- Validate:
  - max file size
  - allowed extensions
- Generate key:

```php
$key = "users/{$userId}/" . uniqid() . "-" . $filename;
```

- Generate presigned URL:
  - method: PUT
  - TTL: 5–10 minutes

---

### Output

```json
{
  "data": {
    "url": "...",
    "key": "users/123/file.jpg"
  }
}
```

---

### Security Rules

- MUST enforce user prefix
- MUST NOT expose credentials
- MUST validate file type before signing

---

---

## 🥈 STEP 2 — Direct Upload (Client)

```js
await fetch(presign.url, {
  method: 'PUT',
  body: file
});
```

---

---

## 🥉 STEP 3 — Upload Complete API

### Endpoint

```
POST /api/upload/complete
```

---

### Input

```json
{
  "key": "users/123/file.jpg",
  "name": "file.jpg",
  "size": 123456,
  "mime": "image/jpeg"
}
```

---

### Logic

- (Optional) verify file exists via HEAD
- Append metadata (JSON)

---

### Idempotency (IMPORTANT)

- unique key = file_key
- if exists → skip insert

---

---

## 🧱 STEP 4 — JSON Metadata (SIMPLE VERSION)

### Structure

```
/users/{userId}/metadata.json
```

---

### Write Strategy

- Read file
- Append new record
- Write back

---

### Example

```json
[
  {
    "key": "users/123/a.jpg",
    "name": "a.jpg",
    "size": 123456,
    "mime": "image/jpeg",
    "created_at": 1710000000
  }
]
```

---

### ⚠️ NOTE

- NO concurrency handling yet
- NO sharding yet
- KEEP SIMPLE

---

---

# 🚀 PHASE 2 — MAKE IT USABLE

## 🎯 Goal

Make dev experience GOOD (important)

---

## 🎖 STEP 5 — Upload SDK (KILLER FEATURE)

```js
const file = await FluxFiles.upload(fileInput);
```

---

### Internal Flow

```
presign → upload → complete
```

---

---

## 🎖 STEP 6 — Error Handling

- retry upload (max 3 times)
- detect expired presign
- re-request presign

---

---

## 🎖 STEP 7 — Demo Page

```
/demo/index.html
```

---

### Features

- drag & drop upload
- preview image
- show URL
- copy button

---

### Goal

> User understands product in < 10 seconds

---

---

# 🚀 PHASE 3 — MAKE IT SAFE

## 🎯 Goal

Fix real-world issues

---

## 🏅 STEP 8 — Atomic Write

### Flow

```
metadata.tmp → metadata.json
```

---

---

## 🏅 STEP 9 — Retry Write

```
try write
if fail:
  re-read
  retry (max 3 times)
```

---

---

## 🏅 STEP 10 — Recovery Strategy

### Problem

Upload success but metadata fail

---

### Solution

- allow orphan files
- on read:
  - detect missing metadata
  - auto-sync (lazy recovery)

---

---

## 🏅 STEP 11 — Source of Truth

```
S3 = source of truth (file exists)
JSON = index (can rebuild)
```

---

---

# 🚀 PHASE 4 — SCALE JSON ENGINE

## 🎯 Goal

Handle large data safely

---

## 🧠 STEP 12 — Metadata Sharding

```
/users/{userId}/metadata/
  ├── 2026-04.json
  ├── 2026-05.json
```

---

---

## 🧠 STEP 13 — Concurrency Control

Options:

- optimistic lock (version/hash)
- retry on conflict

---

---

## 🧠 STEP 14 — Read Strategy

```
1. read all shards
2. merge in memory
3. sort by created_at
```

---

---

## 🧠 STEP 15 — Cache (optional)

- cache metadata in memory
- invalidate on write

---

# 📦 VERSION PLAN

---

## 🚀 v1.27

- presign API
- direct upload
- complete API
- simple JSON metadata

---

## 🚀 v1.28

- upload() SDK
- demo page
- error handling

---

## 🚀 v1.29

- atomic write
- retry write
- recovery strategy

---

## 🚀 v2.0

- sharding
- concurrency control
- caching

---

# 🔥 FINAL PRINCIPLES

```
Server does NOT store files
Server does NOT own metadata
Server only coordinates
```

---

# 💥 FINAL NOTE

```
Start simple → then make it safe → then make it scale
```

