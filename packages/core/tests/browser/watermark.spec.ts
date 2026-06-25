import { test, expect } from '@playwright/test';
import { mintToken, openManager, uploadFile, cardByName, imageFile } from './helpers';

// Watermark editor (free, drag-and-drop). Engine is tested in PHP; here we verify
// the UI wiring: tab opens, text mode posts the right body to /api/fm/watermark.

test('watermark tab: text mode posts to /api/fm/watermark', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `wm-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 300, 200));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();

  // Open the Watermark tab.
  await page.getByRole('button', { name: /Watermark/i }).first().click();

  let posted: any = null;
  await page.route('**/api/fm/watermark', async (route) => {
    posted = route.request().postDataJSON();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { key: name, width: 300, height: 200 } }) });
  });
  await page.route('**/api/fm/list*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }));

  // Drive via the Alpine component (text mode) for determinism.
  await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    c.wm.type = 'text'; c.wm.text = '© Me'; c.wm.x = 0.2; c.wm.y = 0.3; c.wm.opacity = 0.5; c.wm.font_size = 30; c.wm.color = '#ff0000';
    return c.saveWatermark('replace');
  });
  expect(posted).toMatchObject({ type: 'text', text: '© Me', font_size: 30, color: '#ff0000' });
  expect(posted.x).toBeCloseTo(0.2, 5);
});
