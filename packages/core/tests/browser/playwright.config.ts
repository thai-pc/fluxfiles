import { defineConfig } from '@playwright/test';
import { PORT } from './secret';

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
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
