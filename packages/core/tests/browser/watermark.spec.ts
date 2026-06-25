import { test, expect } from '@playwright/test';
import { mintToken, openManager, uploadFile, cardByName, imageFile, pngFile } from './helpers';

// End-to-end watermark editor — drives the REAL UI and the REAL backend (no mocked
// endpoint), so the burn-in path (ImageOptimizer::burnWatermark → applyWatermark →
// file written) is actually exercised. Each test uploads a fresh image.

test('text watermark: Save as Copy creates a burned <name>_wm.png (real)', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `wmtxt-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 420, 300));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });

  await cardByName(page, name).click();
  await page.getByRole('button', { name: /Watermark/i }).first().click();

  await page.locator('.ff-wm-panel').getByRole('button', { name: 'Text', exact: true }).click();
  await page.locator('.ff-wm-panel input.ff-input').fill('(c) Playwright');

  // Real apply → real /api/fm/watermark → a new file appears in the listing.
  await page.getByRole('button', { name: 'Save as Copy' }).click();
  const copy = name.replace('.png', '_wm.png');
  await expect(cardByName(page, copy)).toBeVisible({ timeout: 20_000 });
});

test('logo watermark: upload logo, drag, Apply replaces in place (real)', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `wmlogo-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 500, 360));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });

  await cardByName(page, name).click();
  await page.getByRole('button', { name: /Watermark/i }).first().click();

  // Upload a logo into the watermark tab's file input (logo mode is default).
  await page.locator('.ff-wm-controls input[type=file]').setInputFiles(pngFile('logo.png'));
  const mark = page.locator('.ff-wm-mark').first();
  await expect(mark).toBeVisible({ timeout: 10_000 });

  // Drag the logo overlay toward the LEFT of the stage (stay inside the stage so
  // the mouseleave/up doesn't cancel the drag mid-way).
  const box = await mark.boundingBox();
  const stage = await page.locator('.ff-wm-stage').boundingBox();
  if (box && stage) {
    // Grab near the mark's TOP (the default y=0.85 mark can overflow the clipped
    // stage bottom), then drag to a point well inside the stage.
    await page.mouse.move(box.x + box.width / 2, box.y + 6);
    await page.mouse.down();
    await page.mouse.move(stage.x + stage.width * 0.25, stage.y + stage.height * 0.4, { steps: 8 });
    await page.mouse.up();
  }
  const moved = await page.evaluate(() =>
    (window as any).Alpine.$data(document.querySelector('.ff-app')).wm.x
  );
  expect(moved).toBeLessThan(0.7); // dragged left of the default 0.7

  // Real apply (replace in place) → succeeds (no error toast, card still present).
  await page.getByRole('button', { name: 'Apply watermark' }).click();
  await expect(page.locator('.ff-toast.error')).toHaveCount(0);
  await expect(cardByName(page, name)).toBeVisible({ timeout: 20_000 });
});

test('resize handle increases the logo scale', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `wmsz-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 500, 360));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await page.getByRole('button', { name: /Watermark/i }).first().click();
  await page.locator('.ff-wm-controls input[type=file]').setInputFiles(pngFile('logo2.png'));
  await expect(page.locator('.ff-wm-mark').first()).toBeVisible({ timeout: 10_000 });

  // Place the mark centrally so its (bottom-right) handle is inside the visible
  // stage and there's room to drag right.
  await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    c.wm.x = 0.2; c.wm.y = 0.25;
  });
  await page.waitForTimeout(100);
  const before = await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).wm.scale);
  const handle = page.locator('.ff-wm-handle').first();
  const hb = await handle.boundingBox();
  const stage = await page.locator('.ff-wm-stage').boundingBox();
  if (hb && stage) {
    await page.mouse.move(hb.x + 5, hb.y + 5);
    await page.mouse.down();
    await page.mouse.move(stage.x + stage.width * 0.8, hb.y + 5, { steps: 8 });
    await page.mouse.up();
  }
  const after = await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).wm.scale);
  expect(after).toBeGreaterThan(before);
});
