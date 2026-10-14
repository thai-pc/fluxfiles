import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager, uploadFile, cardByName } from './helpers';

/**
 * Duplicate finder in the usage dashboard — against the real
 * GET /api/fm/duplicates and the real trash route. The token is scoped to a
 * fresh prefix so other specs' uploads never show up as duplicates.
 */
test('duplicates: finds an identical pair, trashing one copy clears the group', async ({ page }) => {
  const prefix = `dupes-${Date.now()}`;
  await openManager(page, mintTokenWithClaims({ prefix }));
  const bytes = Buffer.from('identical-content-' + prefix);
  await uploadFile(page, { name: 'report.txt', mimeType: 'text/plain', buffer: bytes });
  await expect(cardByName(page, 'report.txt')).toBeVisible({ timeout: 15_000 });
  await uploadFile(page, { name: 'report-copy.txt', mimeType: 'text/plain', buffer: bytes });
  await expect(cardByName(page, 'report-copy.txt')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Storage usage' }).first().click();
  const modal = page.locator('.ff-usage-modal');
  await modal.getByRole('button', { name: 'Find duplicates' }).click();

  const group = modal.locator('.ff-dupe-group');
  await expect(group).toHaveCount(1);
  await expect(group).toContainText('2 copies');
  await expect(modal).toContainText('Extra copies use');

  await group.locator('.ff-dupe-row').nth(1).getByRole('button', { name: 'Move to trash' }).click();
  await expect(page.getByText('Copy moved to trash')).toBeVisible();
  await expect(group).toHaveCount(0);
});
