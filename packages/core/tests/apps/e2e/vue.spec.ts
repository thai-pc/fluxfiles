import { test, expect } from '@playwright/test';
import { openHost, uploadFile, cardByName, pngFile } from './helpers';

// Drives the REAL @fluxfiles/vue <FluxFiles> wrapper embedding a live core iframe.

test('vue wrapper: handshake + token auth (upload renders a card)', async ({ page }) => {
  const fm = await openHost(page, 'vue');
  const name = `vue-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });
});

test('vue wrapper: select event bridges a pick to the host', async ({ page }) => {
  const fm = await openHost(page, 'vue');
  const name = `vue-pick-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });

  await cardByName(fm, name).dblclick();
  await expect(page.getByTestId('picked')).toContainText(name, { timeout: 10_000 });
});
