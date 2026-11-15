import { test, expect } from '@playwright/test';
import { mintToken, openManager } from './helpers';

// Doctor AI row: shown only when the report carries an `ai` block, never auto-runs,
// and renders the localised headline + hint for a classified failure (the endpoint
// answers 200 with ok:false for a failed test).
test('doctor AI row: manual test, localised failure + hint', async ({ page }) => {
  await openManager(page, mintToken());
  let calls = 0;
  await page.route('**/api/fm/disk/doctor*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      summary: 'ok', checks: [{ id: 'write', status: 'ok', message: 'Writable', fix: null }],
      ai: { configured: true, provider: 'claude', model: 'claude-sonnet-5-5' },
    } }) }));
  await page.route('**/api/fm/ai/test', (route) => {
    calls++;
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      ok: false, code: 'ai_auth', params: { provider: 'claude', model: 'claude-sonnet-5-5' },
      message: 'x', hint: 'english hint', detail: null, latency_ms: 5,
    }, error: null }) });
  });

  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).openDoctor());
  const row = page.locator('.ff-doctor-ai');
  await expect(row).toBeVisible({ timeout: 10_000 });
  await expect(row).toContainText('claude · claude-sonnet-5-5');
  expect(calls).toBe(0); // opening the panel must not spend a provider call

  await row.getByRole('button').click();
  await expect(row.locator('.ff-doctor-check.is-fail')).toContainText('The AI provider rejected the API key');
  await expect(row.locator('.ff-doctor-fix').first()).toContainText('Check the API key');
  expect(calls).toBe(1);
});
