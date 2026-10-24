import { defineConfig } from '@playwright/test';

// The paid modules, LIVE, through the LARAVEL PROXY adapter: a real Laravel 12
// app (docker-compose.laravel.yml) with fluxfiles/laravel in proxy mode, core +
// all 13 modules installed via Composer, a real license and the real engines.
// The browser only ever talks to Laravel (:8290) — host page, SDK, iframe UI,
// /api/fm/*, and the public share/intake pages.
// FF_LIVE_REUSE=1 uses an already-running stack, FF_LIVE_KEEP=1 leaves it up after.
// Run from packages/core/tests/modules-live (uses its node_modules):
//   npx playwright test -c laravel/playwright.config.ts
export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  workers: 1,
  timeout: 90_000,
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  use: { baseURL: 'http://localhost:8290' },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
