---
paths:
  - "packages/core/api/ImageCompat.php"
  - "packages/core/api/ImageOptimizer.php"
---

**Watermark logo + transparent base gotcha:** intervention/image v3's `place()` with opacity<100 composites through an opaque GD scratch image (`imagecopymerge`), so a logo over a **transparent (alpha) PNG/WebP** base comes out a dark box (JPEG is fine — no alpha). Fix = bake opacity into the logo's own alpha then place at 100% (`ImageCompat::bakeLogoOpacity`, used by both `placeLogo` overlay + `placeLogoAt` burn-in). Don't reintroduce opacity-on-`place()`.
