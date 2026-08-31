import { test, expect } from '@playwright/test';
import { openHost, uploadFile, cardByName, pngFile, largePngFile } from './helpers';

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

// Phase 6 coverage: the local disk in laravel-app/.env is FLUXFILES_LOCAL_PRIVATE=true,
// so FileManager::list() emits gated /api/fm/stream URLs (file.url / variant urls)
// instead of a static path, and img_base/img_srcset drive /api/fm/img. Opening the
// detail panel renders both on the same <img> (src = gated stream, srcset = on-demand
// WebP) — fetch each directly through the real Laravel proxy and check the bytes land.
// Needs a real (>=100px) image: buildSrcset() intentionally blanks img_srcset for
// anything narrower, which the 1x1 pngFile() fixture always triggers.
test('laravel proxy: gated media stream + on-demand img are served through the proxy', async ({ page }) => {
  const fm = await openHost(page, 'laravel');
  const name = `laravel-stream-${Date.now()}.png`;
  await uploadFile(fm, largePngFile(name));
  const card = cardByName(fm, name);
  await expect(card).toBeVisible({ timeout: 15_000 });

  // Single click opens the detail panel (dblclick is reserved for onSelect).
  await card.click();
  const detailImg = fm.locator('.detail-thumb img');
  await expect(detailImg).toBeVisible({ timeout: 10_000 });

  const streamSrc = await detailImg.getAttribute('src');
  expect(streamSrc).toMatch(/\/api\/fm\/stream\?token=/);
  const streamRes = await page.request.get(new URL(streamSrc!, page.url()).toString());
  expect(streamRes.ok()).toBeTruthy();
  expect(streamRes.headers()['content-type'] || '').toContain('image/');

  const srcset = await detailImg.getAttribute('srcset');
  expect(srcset).toBeTruthy();
  const imgUrl = srcset!.split(',')[0].trim().split(/\s+/)[0];
  expect(imgUrl).toMatch(/\/api\/fm\/img\?token=/);
  const imgRes = await page.request.get(new URL(imgUrl, page.url()).toString());
  expect(imgRes.ok()).toBeTruthy();
  expect(imgRes.headers()['content-type'] || '').toContain('image/');
});
