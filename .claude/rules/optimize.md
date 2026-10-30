---
paths:
  - "packages/core/api/OptimizeModule.php"
  - "packages/core/api/PdfOptimizer.php"
  - "packages/core/api/OptimizeStats.php"
---

**Optimization is now FREE/core** (`POST /api/fm/optimize`, `\FluxFiles\OptimizeModule` + `\FluxFiles\PdfOptimizer`, both in `packages/core/api/`): recompress images → WebP at rest (replace/keep, EXIF stripped) + Ghostscript PDF compression + batch + on-upload `auto_optimize` + savings in the usage dashboard (`OptimizeStats`). Opt-in per token via `allow_optimize` (it replaces/deletes originals → `optimize_forbidden` 403 without it); needs `write` (and `delete` to replace the original). It was a paid module but the value was thin once AVIF/WebP delivery became free in `/img`, so it was folded into the MIT core (removed from `ModuleRegistry`; the `optimize_format`/AVIF-at-rest path is gone — WebP-only). The `packages/optimize/` private package is retired. PDF needs `gs` ≥ 10.05.0 on the server → `501 pdf_unavailable` when absent or older.
