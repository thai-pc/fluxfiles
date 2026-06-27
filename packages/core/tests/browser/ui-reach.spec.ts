import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager, uploadFile, cardByName, imageFile } from './helpers';

// Toolbar layout: the action cluster (upload / new folder / rename / bulk …) is
// ONE row that scrolls sideways when crowded — never wraps — and the filter/sort/
// view "tail" sits on its OWN row below it. Plus: a zoomed image is easy to close.

test('toolbar: actions scroll on one row; filter/sort tail on its own row', async ({ page }) => {
  // Desktop layout (>768px so the bulk context buttons render inline), but narrow
  // enough that upload+new folder+import + bulk overflow the action row → must scroll.
  await page.setViewportSize({ width: 900, height: 700 });
  await openManager(page, mintTokenWithClaims({ allow_url_import: true }));

  // Upload + New folder are on-screen and clickable.
  for (const label of ['Upload', 'New folder']) {
    await expect(page.locator('.ff-toolbar').getByRole('button', { name: label })).toBeVisible();
  }

  const actions = page.locator('.ff-toolbar-actions');
  const main = page.locator('.ff-toolbar-main');
  const tail = page.locator('.ff-toolbar-tail');

  // The action cluster scrolls horizontally (overflow-x: auto), so it never wraps.
  expect(await actions.evaluate((el) => getComputedStyle(el).overflowX)).toBe('auto');

  // The filter/sort/view tail is on a SEPARATE row, below the action row.
  const mainBox = (await main.boundingBox())!;
  const tailBox = (await tail.boundingBox())!;
  expect(tailBox.y).toBeGreaterThanOrEqual(mainBox.y + mainBox.height - 1);

  // Force overflow: upload a file and select it → bulk buttons (rename/delete/move/
  // copy/download) appear on the action row, exceeding the available width.
  const name = `tb-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 200, 200));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click(); // select → bulk buttons show
  await expect(page.locator('.ff-toolbar-actions').getByRole('button', { name: 'Rename' })).toBeVisible();

  // The action ROW stays a single line (it scrolls, not wraps) and overflows.
  const m = await actions.evaluate((el) => ({ sw: el.scrollWidth, cw: el.clientWidth }));
  expect(m.sw).toBeGreaterThan(m.cw);
  expect((await main.boundingBox())!.height).toBeLessThan(64);

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
