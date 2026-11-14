import { test, expect } from '@playwright/test';
import { mintToken, openManager, createFolder, enterFolder, pngFile, uploadFile, cardByName } from './helpers';

// Regression: the status bar ("N items · N folders · N files") was hard-coded
// English; it now goes through i18n with singular/plural keys.
test('status bar renders singular/plural counts via i18n', async ({ page }) => {
  await openManager(page, mintToken());
  const name = 'statusbar-' + Date.now();
  await createFolder(page, page, name);
  await enterFolder(page, name);
  const bar = page.locator('.ff-status-bar span').first();
  await expect(bar).toHaveText('0 items');

  await uploadFile(page, pngFile('one.png'));
  await expect(cardByName(page, 'one.png')).toBeVisible({ timeout: 15_000 });
  await expect(bar).toHaveText('1 item · 1 file');

  await uploadFile(page, pngFile('two.png'));
  await expect(cardByName(page, 'two.png')).toBeVisible({ timeout: 15_000 });
  await expect(bar).toHaveText('2 items · 2 files');
});
