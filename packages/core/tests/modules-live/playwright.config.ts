import { defineConfig } from '@playwright/test';

// The paid modules, LIVE: a Docker stack (docker-compose.yml) running core with all
// 13 modules, a real license and the real engines. global-setup stages + starts it;
// FF_LIVE_REUSE=1 uses an already-running stack, FF_LIVE_KEEP=1 leaves it up after.
export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  use: {
    baseURL: 'http://localhost:8190',
    // The OIDC issuer is http://ff-idp:8191 for core (compose DNS) AND the browser.
    launchOptions: { args: ['--host-resolver-rules=MAP ff-idp 127.0.0.1'] },
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
