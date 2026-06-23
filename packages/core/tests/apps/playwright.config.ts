import { defineConfig } from '@playwright/test';
import { CORE_PORT, REACT_PORT, VUE_PORT, LARAVEL_PORT } from './e2e/secret';

// Real-adapter e2e: boots the PHP core backend AND both Vite hosts (React/Vue),
// then drives the *actual* @fluxfiles/{react,vue} wrappers embedding a live core
// iframe — exercising the real postMessage bridge end-to-end. This is the rig
// for debugging the embedded UI through the wrappers (not the standalone /public).
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  globalSetup: './e2e/global-setup.ts',
  globalTeardown: './e2e/global-teardown.ts',
  webServer: [
    {
      // PHP core backend the wrappers embed by iframe. cwd = packages/core so the
      // built-in server's docroot resolves /public, /assets, /api, /storage.
      command: `php -S 127.0.0.1:${CORE_PORT} router.php`,
      cwd: '../..',
      url: `http://127.0.0.1:${CORE_PORT}/public/`,
      reuseExistingServer: false,
      timeout: 30_000,
    },
    {
      command: `npm run react -- --port ${REACT_PORT}`,
      url: `http://127.0.0.1:${REACT_PORT}/`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
    {
      command: `npm run vue -- --port ${VUE_PORT}`,
      url: `http://127.0.0.1:${VUE_PORT}/`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
    {
      // Real Laravel proxy app (gitignored, scaffolded by scripts/setup-laravel-e2e.sh).
      // Self-contained: serves the UI, the SDK, and the proxied /api/fm/* itself.
      command: `php artisan serve --host=127.0.0.1 --port=${LARAVEL_PORT}`,
      cwd: './laravel-app',
      url: `http://127.0.0.1:${LARAVEL_PORT}/`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
  ],
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
