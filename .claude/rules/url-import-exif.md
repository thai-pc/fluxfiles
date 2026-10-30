---
paths:
  - "packages/core/api/UrlImporter.php"
  - "packages/core/api/SsrfGuard.php"
  - "packages/core/api/ExifStripper.php"
---

**URL import** (`POST /api/fm/import-url`, `UrlImporter.php`): synchronous server-side fetch → the upload pipeline. Opt-in per tenant via `allow_url_import` (default false → inert). `SsrfGuard.php` is the shared SSRF denylist (per-hop + post-connect IP check; also used by the BYOB endpoint check). v1 is sync (no Redis/queue — by design); a storage-resident job model would be the v2 path if async is ever needed.

**`strip_exif`** (`ExifStripper.php`, default `false`, forced on in demo mode): lossless container-level removal of EXIF/XMP/IPTC/PNG text from JPEG/PNG/WebP, keeping only Orientation + ICC. For JPEGs it also cuts everything after the main image's EOI (phone MPF secondary images — HDR gain/depth maps with their own GPS — and vendor trailers) and drops the MPF index. Covers `/upload`, `/import-url`, `/tus`, `/extract` entries and Intake portal uploads; never direct-to-bucket uploads.
