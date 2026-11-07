import { test, expect } from '@playwright/test';

// A file NAME carrying HTML (names can be anything on storage written by other
// tools, e.g. SFTP/S3) that gets REFUSED (expiring token url -> refusal notification).
// CKEditor 4's showNotification() and TinyMCE 4/5's notification text render HTML, so
// the plugins must encode the name. No script may run and the name must show as text.
const NAME = '<img src=x onerror="window.__XSS=1">.png';
const EVIL = { name: NAME, mime: 'image/png', url: 'http://core.test/api/fm/stream?token=T', permanent_url: null };

test('CKEditor 4: refusal notification encodes the file name (no script runs)', async ({ page }) => {
  const dialogs: string[] = [];
  page.on('dialog', (d) => { dialogs.push(d.message()); void d.dismiss(); });
  await page.goto('/packages/core/tests/editors/ckeditor4.html');
  await page.waitForFunction(() => (window as any).__edReady === true, null, { timeout: 20_000 });
  await page.locator('.cke_wysiwyg_frame').click();
  await page.evaluate((p) => { (window as any).__ffPayload = p; }, EVIL as any);
  await page.locator('a.cke_button__fluxfiles').click();
  const msg = page.locator('.cke_notification_message').filter({ hasText: 'expiring link' });
  await expect(msg).toContainText('expiring link', { timeout: 5000 });
  await expect(msg).toContainText(NAME); // rendered as literal text
  expect(await msg.locator('img').count()).toBe(0);
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => (window as any).__XSS)).toBeUndefined();
  expect(dialogs).toEqual([]);
});

test('TinyMCE: refusal notification escapes the file name (no script runs)', async ({ page }) => {
  const dialogs: string[] = [];
  page.on('dialog', (d) => { dialogs.push(d.message()); void d.dismiss(); });
  await page.goto('/packages/core/tests/editors/tinymce.html');
  await page.waitForFunction(() => (window as any).__edReady === true, null, { timeout: 20_000 });
  await page.evaluate((p) => { (window as any).__ffPayload = p; }, EVIL as any);
  await page.locator('.tox-tbtn[aria-label="FluxFiles"]').click();
  const body = page.locator('.tox-notification__body').filter({ hasText: 'expiring link' });
  await expect(body).toContainText('expiring link', { timeout: 5000 });
  await expect(body).toContainText(NAME); // rendered as literal text, not double-escaped
  expect(await body.locator('img').count()).toBe(0);
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => (window as any).__XSS)).toBeUndefined();
  expect(dialogs).toEqual([]);
});
