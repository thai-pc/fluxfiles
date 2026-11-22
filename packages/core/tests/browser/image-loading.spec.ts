import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { cardByName, imageFile, mintToken, openManager, uploadFile } from './helpers';

// What the UI downloads to show an image. Each case here used to fetch the full
// original (several MB) for a picture a few hundred pixels wide.

const uploads = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'storage', 'uploads');

/** Media requests from now on: 'img' (/api/fm/img), 'orig' (the stored file) or 'variant'. */
function watchMedia(page: Page, name: string) {
  const seen: string[] = [];
  page.on('request', (r) => {
    const u = r.url();
    if (u.includes('/api/fm/img')) seen.push('img');
    else if (u.includes('/storage/uploads/') && u.includes(name)) seen.push(u.includes('/_variants/') ? 'variant' : 'orig');
  });
  return seen;
}

/** A > 200 KB JPEG written straight to the disk root: no upload, so no stored variants. */
function dropIn(dir: string, name: string) {
  const php = "$im=imagecreatetruecolor(900,700);mt_srand(7);"
    + 'for($y=0;$y<700;$y++)for($x=0;$x<900;$x++)imagesetpixel($im,$x,$y,mt_rand(0,0xFFFFFF));'
    + 'ob_start();imagejpeg($im,null,90);echo base64_encode(ob_get_clean());';
  const bytes = Buffer.from(execFileSync('php', ['-r', php], { maxBuffer: 1 << 26 }).toString(), 'base64');
  expect(bytes.length).toBeGreaterThan(204800);
  mkdirSync(join(uploads, dir), { recursive: true });
  writeFileSync(join(uploads, dir, name), bytes);
}

test('selecting an image loads neither the original nor the closed lightbox', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `load-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 1600, 1000));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });

  const seen = watchMedia(page, name);
  await cardByName(page, name).click();
  await expect(page.locator('.detail-thumb img')).toBeVisible();
  await page.waitForLoadState('networkidle');
  expect(seen.filter((s) => s === 'orig'), 'hidden crop/watermark images must stay unloaded').toEqual([]);
  expect(seen.filter((s) => s === 'img').length, 'only the panel-sized image').toBeLessThanOrEqual(1);
  await expect(page.locator('.ff-preview-img')).not.toHaveAttribute('srcset', /./);

  // Opening the lightbox loads its image.
  await page.locator('.detail-thumb.detail-thumb-clickable').click();
  const big = page.locator('.ff-preview-img');
  await expect(big).toBeVisible();
  await expect.poll(() => big.evaluate((e: HTMLImageElement) => e.naturalWidth)).toBeGreaterThan(0);
});

test('a large image without stored variants gets a resized grid thumbnail', async ({ page }) => {
  const dir = `novar-${Date.now()}`, name = 'photo.jpg';
  dropIn(dir, name);
  try {
    await openManager(page, mintToken());
    const seen = watchMedia(page, name);
    await cardByName(page, dir).dblclick();
    const img = cardByName(page, name).locator('.thumb-img img');
    await expect(img).toHaveClass(/is-loaded/);
    await expect(img).toHaveAttribute('src', /\/api\/fm\/img\?.*&width=400$/);
    expect(seen).not.toContain('orig');
  } finally { rmSync(join(uploads, dir), { recursive: true, force: true }); }
});

test('the grid falls back to the original when /img refuses', async ({ page }) => {
  const dir = `novar429-${Date.now()}`, name = 'photo.jpg';
  dropIn(dir, name);
  try {
    await page.route('**/api/fm/img*', (r) => r.fulfill({ status: 429, body: 'Too many requests' }));
    await openManager(page, mintToken());
    await cardByName(page, dir).dblclick();
    const img = cardByName(page, name).locator('.thumb-img img');
    await expect(img).toHaveAttribute('src', new RegExp(`/storage/uploads/${dir}/${name}$`));
    await expect(img).not.toHaveClass(/is-error/);
    await expect.poll(() => img.evaluate((e: HTMLImageElement) => e.naturalWidth)).toBe(900);
  } finally { rmSync(join(uploads, dir), { recursive: true, force: true }); }
});

/** Natural width / height of the displayed image, once it has loaded (0 while broken). */
const ratio = (page: Page, sel: string) => page.locator(sel).evaluate((e: HTMLImageElement) => (e.naturalHeight ? e.naturalWidth / e.naturalHeight : 0));

test('after an in-place crop the panel and lightbox show the cropped image, also on the next visit', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);
  const name = `crop-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 2400, 1500)); // ratio 1.6, stored medium + large
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await page.locator('.detail-thumb.detail-thumb-clickable').click();
  await expect.poll(() => ratio(page, '.ff-preview-img')).toBeCloseTo(1.6, 1); // browser now holds the old picture
  await page.waitForTimeout(1100);
  // Smaller than the `large` variant: the server writes no new one and the old one stays.
  const res = await page.request.post('/api/fm/crop', {
    headers: { Authorization: `Bearer ${token}` },
    data: { disk: 'local', path: name, x: 0, y: 0, width: 1150, height: 1000 },
  });
  expect(res.ok(), await res.text()).toBe(true);
  for (let visit = 0; visit < 2; visit++) {
    await openManager(page, token); // not reload(): the UI strips the token from the URL
    await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
    await cardByName(page, name).click();
    await expect.poll(() => ratio(page, '.detail-thumb img'), { message: 'panel' }).toBeCloseTo(1.15, 1);
    await page.locator('.detail-thumb.detail-thumb-clickable').click();
    await expect.poll(() => ratio(page, '.ff-preview-img'), { message: 'lightbox' }).toBeCloseTo(1.15, 1);
  }
});

test('panel and lightbox fall back to the original when /img refuses', async ({ page }) => {
  await page.route('**/api/fm/img*', (r) => r.fulfill({ status: 429, body: 'Too many requests' }));
  await openManager(page, mintToken());
  const name = `refused-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 1600, 1000));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await expect.poll(() => ratio(page, '.detail-thumb img'), { message: 'panel' }).toBeCloseTo(1.6, 1);
  await page.locator('.detail-thumb.detail-thumb-clickable').click();
  await expect.poll(() => ratio(page, '.ff-preview-img'), { message: 'lightbox' }).toBeCloseTo(1.6, 1);
});

test('names with # and % show in the panel and lightbox', async ({ page }) => {
  await openManager(page, mintToken());
  for (const name of [`a #1 ${Date.now()}.png`, `b 100%25 ${Date.now()}.png`]) {
    await uploadFile(page, imageFile(name, 2400, 1500));
    await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
    await cardByName(page, name).click();
    await expect.poll(() => ratio(page, '.detail-thumb img'), { message: name }).toBeCloseTo(1.6, 1);
    await page.locator('.detail-thumb.detail-thumb-clickable').click();
    await expect.poll(() => ratio(page, '.ff-preview-img'), { message: name }).toBeCloseTo(1.6, 1);
    await page.locator('.ff-preview-close').click();
  }
});
