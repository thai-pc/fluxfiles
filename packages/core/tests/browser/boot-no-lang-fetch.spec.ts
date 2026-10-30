import { test, expect } from '@playwright/test';

// Standalone boot: the server already injects the resolved locale + messages
// (window.__FM_LOCALE__), so the UI must not spend a serial round-trip on
// GET /api/fm/lang just to read Content-Language.
test('standalone boot makes no /api/fm/lang request and is still translated', async ({ page }) => {
  const langReqs: string[] = [];
  page.on('request', (r) => { if (r.url().includes('/api/fm/lang')) langReqs.push(r.url()); });

  await page.goto('/public/index.html?disk=local');
  await expect(page.locator('.ff-app')).toBeAttached();
  await page.waitForFunction(() => {
    const c = (window as any).Alpine?.$data(document.querySelector('.ff-app'));
    return c && c.i18nReady;
  });
  const sample = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return c.t('common.save');
  });
  expect(sample).not.toBe('common.save');
  await page.waitForTimeout(500);
  expect(langReqs).toEqual([]);
});
