import { defineConfig } from '@playwright/test';
import { PORT } from './secret';

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  // Serialize: the backend is a single-threaded `php -S`, so running spec files in
  // parallel makes one test's slow request (e.g. WebP variant generation) block
  // another's, causing action/expect timeouts. One worker = no cross-file
  // contention (the flake source for preview-zoom et al. under full-suite load).
  workers: 1,
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  use: { baseURL: `http://127.0.0.1:${PORT}` },
  webServer: {
    command: `php -S 127.0.0.1:${PORT} router.php`,
    cwd: '../..',
    url: `http://127.0.0.1:${PORT}/public/`,
    reuseExistingServer: false,
    timeout: 30_000,
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
