import { defineConfig } from '@playwright/test';

// Multisite (network-activated) real-plugin e2e; see wp-multisite.spec.ts.
export default defineConfig({
  testDir: '.',
  testMatch: 'wp-multisite.spec.ts',
  fullyParallel: false,
  workers: 1,
  use: { baseURL: 'http://localhost:8890' },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
