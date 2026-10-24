import { defineConfig } from '@playwright/test';

// Paid modules LIVE through the WordPress plugin proxy: a Docker stack
// (docker-compose.wordpress.yml, project ff-live-wp) running WordPress + the real
// plugin artifact + all 13 modules + a license pasted into the plugin's option +
// the real engines. global-setup stages + starts it; FF_LIVE_REUSE=1 uses an
// already-running stack, FF_LIVE_KEEP=1 leaves it up afterwards.
export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  workers: 1,
  timeout: 90_000,
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  outputDir: './test-results',
  use: { baseURL: 'http://localhost:8390', viewport: { width: 1440, height: 1100 } },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
