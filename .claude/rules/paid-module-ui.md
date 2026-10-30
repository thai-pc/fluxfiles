---
paths:
  - "packages/core/assets/fm.js"
  - "packages/core/public/index.html"
---

**Operator UI for paid modules** — all behind the same `proGate('allow_x', '<module>')` three-state treatment (hidden / locked teaser / on): Share, Intake, Versioning, **AI Vision** (modal for bg_remove/upscale/smart_crop + detail-panel/context-menu/action-sheet entries; `canAiVisionFile()` mirrors `ImageOptimizer::isImage()`'s raster set, no SVG), **OCR** (language picker, copy, "save as caption"), **Virus** ("uploads are scanned" note, per-batch clean-scan toast, on-demand "Scan for viruses" → `POST /api/fm/virus/scan`), **Backup** (folder → another of the token's disks, copied/skipped/errors summary), **Audit export** (inside the Activity panel, plus "Integrity check" → `/fixity/check` and "Verify log" → `/audit/verify`), **Legal hold** (place/release, plus "Retention" → `/retention/run` when the token carries `retention_rules`). C2PA, Webhooks and DLP remain engine-only (API/config, no UI); SSO adds only the "Sign in with SSO" button.
