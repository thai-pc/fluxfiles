import { test, expect } from '@playwright/test';
import { mintToken, imageFile, cardByName, openManager, uploadFile } from './helpers';

// Image preview lightbox zoom: +/−/reset controls, the % level, the zoomed cursor
// state, and the close-gating (a backdrop click closes only when not zoomed).

test('lightbox zoom: controls, level, reset, and close gating', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `zoom-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 600, 400));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });

  await cardByName(page, name).click();        // open detail panel
  // Wait until the thumb is actually clickable (detailFile.url present) before
  // opening the lightbox — avoids a no-op click before the detail panel settles.
  await expect(page.locator('.detail-thumb.detail-thumb-clickable')).toBeVisible();
  await page.locator('.detail-thumb').click();  // open the fullscreen lightbox

  const lb = page.locator('.ff-preview-lightbox');
  await expect(lb).toBeVisible();
  const img = lb.locator('.ff-preview-img');
  const level = lb.locator('.ff-zoom-level');

  // starts at 100%, zoom-out + reset disabled.
  await expect(level).toHaveText('100%');
  await expect(lb.getByRole('button', { name: 'zoom out' })).toBeDisabled();
  await expect(lb.getByRole('button', { name: 'reset zoom' })).toBeDisabled();

  // zoom in → 150%, image marked zoomed.
  await lb.getByRole('button', { name: 'zoom in' }).click();
  await expect(level).toHaveText('150%');
  await expect(img).toHaveClass(/zoomed/);

  // a backdrop click while zoomed must NOT close the lightbox.
  await lb.click({ position: { x: 6, y: 6 } });
  await expect(lb).toBeVisible();

  // reset → 100%, no longer zoomed.
  await lb.getByRole('button', { name: 'reset zoom' }).click();
  await expect(level).toHaveText('100%');
  await expect(img).not.toHaveClass(/zoomed/);

  // now a backdrop click closes it.
  await lb.click({ position: { x: 6, y: 6 } });
  await expect(lb).toBeHidden();
});

test('zoom clamps to 100%–500% and reopening starts fresh', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `zoomclamp-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 500, 500));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await expect(page.locator('.detail-thumb.detail-thumb-clickable')).toBeVisible();
  await page.locator('.detail-thumb').click();

  const lb = page.locator('.ff-preview-lightbox');
  const level = lb.locator('.ff-zoom-level');
  const zin = lb.getByRole('button', { name: 'zoom in' });

  // step to the 500% ceiling (1.0 → +0.5 ×8 = 5.0); the 8th click disables zoom-in.
  for (let i = 0; i < 8; i++) await zin.click();
  await expect(level).toHaveText('500%');
  await expect(zin).toBeDisabled();

  // Escape closes immediately, even when zoomed (one press).
  await page.keyboard.press('Escape');
  await expect(lb).toBeHidden();

  // reopening starts at 100% (zoom is reset on open).
  await page.locator('.detail-thumb').click();
  await expect(level).toHaveText('100%');
});
