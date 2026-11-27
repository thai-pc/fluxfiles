import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager, uploadFile, cardByName } from './helpers';

/**
 * Phone-width search: the full-screen search overlay used to cover the whole viewport
 * with an opaque layer and no result list of its own, so a match was found but never
 * visible (closing the overlay with × also cleared the query). Once a query is long
 * enough to search, the overlay shrinks to its header and the results below must be
 * the topmost element where they are drawn.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('mobile search: results are visible under the search bar', async ({ page }) => {
  const prefix = `msearch-${Date.now()}`;
  await openManager(page, mintTokenWithClaims({ prefix }));
  await uploadFile(page, { name: 'findme-alpha.txt', mimeType: 'text/plain', buffer: Buffer.from('a') });
  await expect(cardByName(page, 'findme-alpha.txt')).toBeVisible({ timeout: 15_000 });

  await page.locator('.ff-mobile-search-btn').click();
  const overlay = page.locator('.ff-mobile-search-overlay');
  await expect(overlay).toBeVisible();
  await page.locator('[x-ref="mobileSearchInput"]').fill('findme');

  const hit = page.locator('.file-grid .file-card', { hasText: 'findme-alpha.txt' }).first();
  await expect(hit).toBeVisible({ timeout: 15_000 });
  // Visible to Playwright is not enough: the overlay used to paint over it.
  const onTop = await hit.evaluate((el) => {
    const r = el.getBoundingClientRect();
    const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return !!top && el.contains(top);
  });
  expect(onTop).toBe(true);
  // The search bar itself stays open and keeps the query.
  await expect(page.locator('[x-ref="mobileSearchInput"]')).toHaveValue('findme');

  // A one-letter query does not search yet: the overlay still covers the page with its hint.
  await page.locator('[x-ref="mobileSearchInput"]').fill('f');
  await expect(page.locator('.ff-mobile-search-hint')).toBeHidden();
  await expect(overlay).not.toHaveClass(/has-query/);
});
