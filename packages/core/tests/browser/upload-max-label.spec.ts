import { test, expect, type Page } from '@playwright/test';
import { mintToken, mintTokenWithClaims } from './helpers';

// The drop zone's "Max N per file" label must reflect the effective cap (boot config,
// else the token's max_upload claim), not a hard-coded 10MB.
async function label(page: Page, boot: Record<string, unknown>) {
  await page.addInitScript((b) => {
    (window as unknown as { __FM_BOOT__: unknown }).__FM_BOOT__ = { ...b, endpoint: location.origin };
  }, boot);
  await page.goto('/public/index.html');
  await expect(page.locator('.ff-app')).toBeAttached();
  await page.waitForFunction(() => {
    const c = (window as any).Alpine?.$data(document.querySelector('.ff-app'));
    return c && c.i18nReady;
  });
  return page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).uploadMaxLabel);
}

test('drop zone label follows the boot maxUploadMb (demo mode)', async ({ page }) => {
  expect(await label(page, { token: mintToken(), disk: 'local', maxUploadMb: 5 })).toBe('5MB');
  await expect(page.locator('.ff-dropzone')).toContainText('5MB', { useInnerText: false });
  await expect(page.locator('.ff-dropzone')).not.toContainText('10MB');
});

test('drop zone label falls back to the token max_upload claim', async ({ page }) => {
  expect(await label(page, { token: mintTokenWithClaims({ max_upload: 7 }), disk: 'local' })).toBe('7MB');
});
