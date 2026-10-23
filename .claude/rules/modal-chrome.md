---
paths:
  - "packages/react/**"
  - "packages/vue/**"
  - "packages/sdk/**"
---

**Modal chrome dark-mode anti-flash:** the React/Vue/SDK modal chrome lives in the HOST origin and can't read the cross-origin iframe's resolved theme, so when the `theme` prop is `auto`/unset it mirrors the embedded UI's boot: resolve dark from `localStorage['fluxfiles_theme']` first, then OS, and persist an explicit theme. Computing chrome `dark` only from `prefers-color-scheme` flashes a light header before a dark theme settles.
