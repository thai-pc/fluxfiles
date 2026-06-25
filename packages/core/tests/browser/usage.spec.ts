import { test, expect } from '@playwright/test';
import { mintToken, openManager, createFolder, enterFolder, uploadFile, pngFile } from './helpers';

// Storage usage dashboard (M4). The panel reads GET /api/fm/usage; we drive it
// both with a mocked response (deterministic UI assertions) and against the real
// endpoint (end-to-end render).

test('usage dashboard renders quota meter, by-type and top-folders from a mocked response', async ({ page }) => {
  await openManager(page, mintToken());

  await page.route('**/api/fm/usage*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          computed_at: new Date().toISOString(),
          cache_age_seconds: 0,
          quota: { used_bytes: 4_500_000_000, limit_bytes: 5_000_000_000, percent: 90, status: 'critical' },
          file_count: 142,
          content_bytes: 4_500_000_000,
          by_type: [
            { type: 'image', size_bytes: 3_000_000_000, count: 120, percent: 66.7 },
            { type: 'video', size_bytes: 1_500_000_000, count: 22, percent: 33.3 },
          ],
          top_folders: [
            { path: '/products', size_bytes: 3_200_000_000, count: 100 },
            { path: '/imports', size_bytes: 1_300_000_000, count: 42 },
          ],
        },
      }),
    }),
  );

  // Open via the toolbar button.
  await page.getByRole('button', { name: 'Storage usage' }).first().click();

  const modal = page.locator('.ff-usage-modal');
  await expect(modal).toBeVisible();
  // Critical status → red meter + critical banner.
  await expect(modal.locator('.ff-usage-meter.is-critical')).toBeVisible();
  await expect(modal.locator('.ff-usage-banner.is-critical')).toBeVisible();
  await expect(modal).toContainText('90%');
  await expect(modal).toContainText('142');
  // By-type rows (localised labels).
  await expect(modal.locator('.ff-usage-row')).toHaveCount(2);
  await expect(modal).toContainText('Images');
  await expect(modal).toContainText('Videos');
  // Top folders.
  await expect(modal.locator('.ff-usage-folder')).toHaveCount(2);
  await expect(modal).toContainText('/products');
});

test('clicking a top folder navigates into it and closes the dashboard', async ({ page }) => {
  await openManager(page, mintToken());

  await page.route('**/api/fm/usage*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          quota: { used_bytes: 1000, limit_bytes: null, percent: null, status: 'ok' },
          file_count: 1, content_bytes: 1000,
          by_type: [{ type: 'image', size_bytes: 1000, count: 1, percent: 100 }],
          top_folders: [{ path: '/album', size_bytes: 1000, count: 1 }],
          cache_age_seconds: 0,
        },
      }),
    }),
  );

  await page.getByRole('button', { name: 'Storage usage' }).first().click();
  await expect(page.locator('.ff-usage-modal')).toBeVisible();

  await page.locator('.ff-usage-folder', { hasText: '/album' }).click();
  // Modal closes and the manager navigates to that folder.
  await expect(page.locator('.ff-usage-modal')).toBeHidden();
  const path = await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).currentPath);
  expect(path).toBe('album');
});

test('usage dashboard works against the real endpoint after an upload', async ({ page }) => {
  await openManager(page, mintToken());
  await createFolder(page, page, `usage-${Date.now()}`);
  // (folder created; upload at root is enough for a non-empty breakdown)
  await uploadFile(page, pngFile('usage-pic.png'));

  await page.getByRole('button', { name: 'Storage usage' }).first().click();
  const modal = page.locator('.ff-usage-modal');
  await expect(modal).toBeVisible();
  // Real data: at least one image in the by-type breakdown.
  await expect(modal.locator('.ff-usage-row')).not.toHaveCount(0);
  await expect(modal).toContainText('Images');
});

test('license banner: edition + grace note from a mocked /license', async ({ page }) => {
  await openManager(page, mintToken());
  await page.route('**/api/fm/usage*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      quota: { used_bytes: 1, limit_bytes: 0, percent: 0, status: 'ok' }, file_count: 0, by_type: [], top_folders: [],
    } }) }));
  await page.route('**/api/fm/license', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      edition: 'pro', status: 'grace', enforcement: 'subscription', modules: ['optimize'], days_left: 7,
    } }) }));

  await page.getByRole('button', { name: 'Storage usage' }).first().click();
  const banner = page.locator('.ff-license-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toContainText('Pro');
  await expect(banner).toContainText('grace period');
  await expect(banner).toContainText('7'); // days left
});
