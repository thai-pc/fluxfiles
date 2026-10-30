---
paths:
  - "packages/virus/**"
  - "packages/dlp/**"
  - "packages/core/api/ChunkUploader.php"
---

**Virus scan is wired fail-closed, and that's load-bearing.** `allow_virus_scan` makes `index.php` (and the Laravel/WP proxies, which build their own `FileManager`) set `FileManager::setVirusScanner()`; the `ModuleRegistry::require` gate runs **inside** the callback so reads still work and only writes needing a scan fail. Scanned: `upload()` (so also `/import-url` and `/tus`), `putContent()`, every `extractZip()` entry — each **before** the bytes are written. Anything that prevents a scan (module absent 501 / licence 402 / claim 403 / engine down 502) **refuses the write**; a malformed verdict counts as infected. `/api/fm/chunk/*` and presigned PUT/POST return `409 virus_unscannable` while the claim is on, because those bytes go browser→S3 and can never be scanned — don't "fix" that by letting the route through. The module scans by **path** (`scanPath`), not bytes, so uploads aren't loaded into memory.
