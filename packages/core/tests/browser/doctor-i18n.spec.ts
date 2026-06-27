import { test, expect } from '@playwright/test';
import { mintToken, openManager } from './helpers';

// Bucket/disk doctor (permission checks) must localise the check LABELS. The
// per-check message/fix stay English (provider/AWS technical detail) by design.
test('doctor check labels localise (vi); raw id is the fallback', async ({ page }) => {
  await openManager(page, mintToken()); // has write perm → canDiagnose
  await page.route('**/api/fm/disk/doctor*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      summary: 'fail',
      checks: [
        { id: 'reachability', status: 'fail', message: 'Cannot reach bucket', fix: 'Verify creds' },
        { id: 'write', status: 'ok', message: 'Writable', fix: null },
        { id: 'totally_unknown', status: 'info', message: 'x', fix: null },
      ],
    } }) }));

  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).switchLocale('vi'));
  await page.waitForTimeout(400);
  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).openDoctor());

  const checks = page.locator('.ff-doctor-check');
  await expect(checks.first()).toBeVisible({ timeout: 10_000 });
  // reachability → Vietnamese label; message stays English.
  await expect(checks.nth(0)).toContainText('Kết nối & liệt kê');
  await expect(checks.nth(0)).toContainText('Cannot reach bucket');
  await expect(checks.nth(1)).toContainText('Ghi'); // write
  // Unknown id falls back to the raw id (no crash, no blank label).
  await expect(checks.nth(2)).toContainText('totally_unknown');
});
