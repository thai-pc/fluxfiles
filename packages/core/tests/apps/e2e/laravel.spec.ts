import { test, expect } from '@playwright/test';
import { openHost, uploadFile, cardByName, pngFile } from './helpers';

// Drives the REAL Laravel adapter in PROXY mode: the <x-fluxfiles> Blade
// component mints a JWT server-side (FluxFilesManager), loads the SDK from the
// proxy, embeds the core UI, and every /api/fm/* call is proxied through Laravel
// (FluxFilesController → core FileManager). Exercises the full adapter stack.

test('laravel proxy: component boots + proxied API auth (upload renders a card)', async ({ page }) => {
  const fm = await openHost(page, 'laravel');
  const name = `laravel-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });
});

test('laravel proxy: onSelect bridges a pick to the host', async ({ page }) => {
  const fm = await openHost(page, 'laravel');
  const name = `laravel-pick-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });

  await cardByName(fm, name).dblclick();
  await expect(page.getByTestId('picked')).toContainText(name, { timeout: 10_000 });
});
