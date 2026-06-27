import { defineConfig } from '@playwright/test';

// Serve the whole repo root statically so a harness page can load both the
// vendored editor libs (this dir's node_modules/) and each plugin's shipped
// plugin.min.js (packages/<editor>/plugin.min.js) from one origin.
const REPO_ROOT = require('path').resolve(__dirname, '../../../..');
const PORT = Number(process.env.FF_EDITORS_PORT || 8197);

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  timeout: 30_000,
  use: { baseURL: `http://127.0.0.1:${PORT}` },
  webServer: {
    command: `php -S 127.0.0.1:${PORT} -t ${REPO_ROOT}`,
    url: `http://127.0.0.1:${PORT}/packages/core/tests/editors/tinymce.html`,
    reuseExistingServer: !process.env.CI,
    timeout: 20_000,
  },
});
