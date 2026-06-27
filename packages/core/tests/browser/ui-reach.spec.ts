import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager, uploadFile, cardByName, imageFile } from './helpers';

// Two UX bugs: toolbar buttons (upload / new folder / rename / bulk …) should sit
// on ONE row that scrolls sideways when crowded — not wrap to a second line — and
// a zoomed image being hard to close.

test('toolbar stays one row and scrolls horizontally when crowded', async ({ page }) => {
  // Desktop layout (>768px so the bulk context buttons render inline), but narrow
  // enough that upload+new folder+import + bulk + tail overflow → must scroll, not wrap.
  await page.setViewportSize({ width: 900, height: 700 });
  await openManager(page, mintTokenWithClaims({ allow_url_import: true }));

  // Upload + New folder are on-screen and clickable.
  for (const label of ['Upload', 'New folder']) {
    await expect(page.locator('.ff-toolbar').getByRole('button', { name: label })).toBeVisible();
  }

  // The toolbar is a SINGLE row (no wrapping to a 2nd line → height ~ one button).
  const tbH = (await page.locator('.ff-toolbar').boundingBox())!.height;
  expect(tbH).toBeLessThan(64);

  // The action cluster scrolls horizontally (overflow-x: auto), so it never wraps.
  const actions = page.locator('.ff-toolbar-actions');
  expect(await actions.evaluate((el) => getComputedStyle(el).overflowX)).toBe('auto');

  // Force overflow: upload a file and select it → bulk buttons (rename/delete/move/
  // copy/download/zip) appear on the same row, exceeding 480px.
  const name = `tb-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 200, 200));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click(); // select → bulk buttons show
  await expect(page.locator('.ff-toolbar-actions').getByRole('button', { name: 'Rename' })).toBeVisible();

  // Now the cluster overflows and is actually scrollable (content wider than box),
  // and the toolbar is STILL a single row.
  const m = await actions.evaluate((el) => ({ sw: el.scrollWidth, cw: el.clientWidth }));
  expect(m.sw).toBeGreaterThan(m.cw);
  expect((await page.locator('.ff-toolbar').boundingBox())!.height).toBeLessThan(64);

  // Scrolling reveals the far buttons (scrollLeft moves off zero).
  await actions.evaluate((el) => { el.scrollLeft = el.scrollWidth; });
  expect(await actions.evaluate((el) => el.scrollLeft)).toBeGreaterThan(0);
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
