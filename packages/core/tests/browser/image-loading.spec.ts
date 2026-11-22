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

test('the detail panel and lightbox use stored variants before resizing on demand', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `variants-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 2400, 1500));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  const seen = watchMedia(page, name);
  await cardByName(page, name).click();
  const img = page.locator('.detail-thumb img');
  await expect(img).toBeVisible();
  const srcset = (await img.getAttribute('srcset'))!;
  expect(srcset).toMatch(/_medium\.webp\S* 768w, \S+_large\.webp\S* 1920w/);
  const widths = [...srcset.matchAll(/ (\d+)w/g)].map((m) => Number(m[1]));
  expect([...widths].sort((a, b) => a - b), 'no smaller on-demand candidates left').toEqual(widths);
  await page.waitForLoadState('networkidle');
  expect(seen).not.toContain('img');
  expect(seen).not.toContain('orig');
});
