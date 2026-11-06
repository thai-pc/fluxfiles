import { defineConfig } from '@playwright/test';
import { STATIC_PORT, CORE_PORT, CK5_PORT } from './real/env';

// Serve the whole repo root statically so a harness page can load both the
// vendored editor libs (this dir's node_modules/) and each plugin's shipped
// plugin.min.js (packages/<editor>/plugin.min.js) from one origin.
const REPO_ROOT = require('path').resolve(__dirname, '../../../..');
const PORT = STATIC_PORT;

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  timeout: 30_000,
  // real-picker specs write a throwaway FLUXFILES_SECRET into the core .env (backed up/restored).
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  use: { baseURL: `http://127.0.0.1:${PORT}` },
  webServer: [
    {
      command: `php -S 127.0.0.1:${PORT} -t ${REPO_ROOT}`,
      url: `http://127.0.0.1:${PORT}/packages/core/tests/editors/tinymce.html`,
      reuseExistingServer: !process.env.CI,
      timeout: 20_000,
    },
    {
      // Live FluxFiles core: serves the picker iframe the editors open.
      // cwd = packages/core so router.php resolves /public, /api, /storage.
      command: `php -S 127.0.0.1:${CORE_PORT} router.php`,
      cwd: '../..',
      url: `http://127.0.0.1:${CORE_PORT}/public/`,
      reuseExistingServer: false,
      timeout: 30_000,
    },
    {
      // Bundles the CKEditor 5 adapter source + real ckeditor5 for the CK5 spec.
      command: `npx vite --config real/ck5/vite.config.mjs --port ${CK5_PORT}`,
      url: `http://127.0.0.1:${CK5_PORT}/ckeditor5.html`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
  ],
});
