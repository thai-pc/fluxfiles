import { defineConfig } from '@playwright/test';

// WordPress real-plugin e2e. wp-env (Docker: WP + MySQL) is managed out-of-band
// by ./setup.sh, so there's no Playwright webServer here — the suite just drives
// the running site at :8888. Run: bash setup.sh && npx playwright test (or
// `npm run e2e:wp` from tests/apps).
export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  workers: 1,
  use: { baseURL: 'http://localhost:8888' },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
