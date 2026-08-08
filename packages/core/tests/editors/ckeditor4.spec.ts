import { test, expect, Page } from '@playwright/test';

// Live-browser test: REAL CKEditor 4 + the REAL shipped plugin.min.js.

const BURN_IN = { url: 'https://cdn.example.com/burned.png', name: 'burned.png', mime: 'image/png', type: 'image' };
const PREVIEW_ONLY = { name: 'protected.png', mime: 'image/png', img_base: '/api/fm/img?token=t', url: undefined, permanent_url: null };
// Malicious alt_text containing an unescaped `"` used to break out of the alt="..."
// attribute and inject an onerror handler (stored XSS). See packages/{ckeditor4,
// tinymce,summernote} — all three built inserted <img> HTML by string concatenation
// with an "escaper" that never encoded `"`.
const XSS_ALT = { url: '/nonexistent.png', name: 'a.png', mime: 'image/png', meta: { alt_text: 'x" onerror="window.__XSS=1" y="' } };

async function ready(page: Page) {
  await page.goto('/packages/core/tests/editors/ckeditor4.html');
  await page.waitForFunction(() => (window as any).__edReady === true, null, { timeout: 20_000 });
  // CKE4 insertHtml needs a real selection in the (iframe) editable — click into
  // it first so a burn-in image actually lands, like a user would before inserting.
  await page.locator('.cke_wysiwyg_frame').click();
}
const getData = (page: Page) => page.evaluate(() => (window as any).CKEDITOR.instances.ed.getData());
const clickButton = (page: Page) => page.locator('a.cke_button__fluxfiles').click();

test('burn-in image (has url) is inserted as <img>', async ({ page }) => {
  await ready(page);
  await page.evaluate((p) => { (window as any).__ffPayload = p; }, BURN_IN);
  await clickButton(page);
  await expect.poll(() => getData(page)).toContain('src="https://cdn.example.com/burned.png"');
});

test('preview-only image (watermark overlay) is NOT inserted, and warns', async ({ page }) => {
  const warnings: string[] = [];
  page.on('console', (m) => { if (m.type() === 'warning') warnings.push(m.text()); });
  await ready(page);
  await page.evaluate((p) => { (window as any).__ffPayload = p; }, PREVIEW_ONLY as any);
  await clickButton(page);
  await page.waitForTimeout(300);
  const html = await getData(page);
  expect(html).not.toContain('<img');
  expect(html).not.toContain('img_base');
  expect(warnings.join('\n')).toMatch(/preview-only|burn in the watermark/i);
});

test('a `"` in alt_text is escaped, not left to break out of the alt attribute (stored XSS)', async ({ page }) => {
  await ready(page);
  await page.evaluate((p) => { (window as any).__ffPayload = p; }, XSS_ALT as any);
  await clickButton(page);
  await expect.poll(() => getData(page)).toContain('src="/nonexistent.png"');
  const html = await getData(page);
  expect(html).not.toContain('onerror="window.__XSS=1"');
  const xss = await page.evaluate(() => (window as any).__XSS);
  expect(xss).toBeUndefined();
});
