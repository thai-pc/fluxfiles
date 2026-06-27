import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager, uploadFile, cardByName, imageFile } from './helpers';

// Two UX bugs: toolbar buttons clipped (no scroll/wrap) in a narrow container, and
// a zoomed image being hard to close.

test('toolbar buttons wrap + stay clickable in a narrow viewport', async ({ page }) => {
  await page.setViewportSize({ width: 560, height: 700 });
  await openManager(page, mintTokenWithClaims({ allow_url_import: true }));
  // Upload + New folder must be fully on-screen and clickable (not clipped).
  for (const label of ['Upload', 'New folder']) {
    const btn = page.locator('.ff-toolbar').getByRole('button', { name: label });
    await expect(btn).toBeVisible();
    const box = await btn.boundingBox();
    expect(box).not.toBeNull();
    // Right edge within the viewport (not clipped off-screen).
    expect(box!.x + box!.width).toBeLessThanOrEqual(560 + 1);
  }
  // The toolbar wrapped to more than one row (height > a single button row).
  const tbH = (await page.locator('.ff-toolbar').boundingBox())!.height;
  expect(tbH).toBeGreaterThan(44);
});

test('zoomed image: close button dismisses the lightbox', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({}));
  const name = `zoomclose-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 600, 400));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await page.locator('.detail-thumb.detail-thumb-clickable').click();

  const lb = page.locator('.ff-preview-lightbox');
  await expect(lb).toBeVisible();
  // Zoom in (so a backdrop click can't close it — it pans).
  await lb.getByRole('button', { name: 'zoom in' }).click();
  await expect(lb.locator('.ff-zoom-level')).toHaveText('150%');
  // The always-visible close button still dismisses it.
  await lb.getByRole('button', { name: 'Close' }).click();
  await expect(lb).toBeHidden();
});

test('zoomed image: Escape closes immediately (one press)', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({}));
  const name = `zoomesc-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 600, 400));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await page.locator('.detail-thumb.detail-thumb-clickable').click();
  const lb = page.locator('.ff-preview-lightbox');
  await expect(lb).toBeVisible();
  await lb.getByRole('button', { name: 'zoom in' }).click();
  await page.keyboard.press('Escape');
  await expect(lb).toBeHidden();
});
