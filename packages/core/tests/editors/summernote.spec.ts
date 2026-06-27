import { test, expect, Page } from '@playwright/test';

// Live-browser test: REAL Summernote (lite) + jQuery + the REAL shipped plugin.min.js.

const BURN_IN = { url: 'https://cdn.example.com/burned.png', name: 'burned.png', mime: 'image/png', type: 'image' };
const PREVIEW_ONLY = { name: 'protected.png', mime: 'image/png', img_base: '/api/fm/img?token=t', url: undefined, permanent_url: null };

async function ready(page: Page) {
  await page.goto('/packages/core/tests/editors/summernote.html');
  await page.waitForFunction(() => (window as any).__edReady === true, null, { timeout: 20_000 });
}
const getCode = (page: Page) => page.evaluate(() => (window as any).jQuery('#ed').summernote('code'));
// The plugin registers its button in the toolbar group named "fluxfiles" → .note-fluxfiles.
const clickButton = (page: Page) => page.locator('.note-fluxfiles button').first().click();

test('burn-in image (has url) is inserted as <img>', async ({ page }) => {
  await ready(page);
  await page.evaluate((p) => { (window as any).__ffPayload = p; }, BURN_IN);
  await clickButton(page);
  await expect.poll(() => getCode(page)).toContain('src="https://cdn.example.com/burned.png"');
});

test('preview-only image (watermark overlay) is NOT inserted, and warns', async ({ page }) => {
  const warnings: string[] = [];
  page.on('console', (m) => { if (m.type() === 'warning') warnings.push(m.text()); });
  await ready(page);
  await page.evaluate((p) => { (window as any).__ffPayload = p; }, PREVIEW_ONLY as any);
  await clickButton(page);
  await page.waitForTimeout(300);
  const html = await getCode(page);
  expect(html).not.toContain('<img');
  expect(html).not.toContain('img_base');
  expect(warnings.join('\n')).toMatch(/preview-only|burn in the watermark/i);
});
