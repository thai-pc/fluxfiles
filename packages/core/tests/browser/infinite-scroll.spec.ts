import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mintToken, cardByName, openManager } from './helpers';

// Infinite scroll on /api/fm/list: the first page (200) renders, the rest
// streams in as the grid is scrolled, a failed page pauses with a Retry, and the
// end of the folder is marked. The files are written straight into the local disk
// root — uploading 450 through the UI would dominate the run time.

const uploadsDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'storage', 'uploads');
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
  'base64',
);

function seedFolder(count: number): string {
  const folder = `scroll-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  mkdirSync(join(uploadsDir, folder), { recursive: true });
  for (let i = 0; i < count; i++) {
    writeFileSync(join(uploadsDir, folder, `f${String(i).padStart(4, '0')}.png`), PNG);
  }
  return folder;
}

async function openFolder(page, folder: string) {
  await openManager(page, mintToken());
  await cardByName(page, folder).dblclick();
  await expect(page.locator('.file-grid .file-card').first()).toBeVisible({ timeout: 15_000 });
}

const cards = (page) => page.locator('.file-grid:not(.ff-more-skeleton) .file-card');
const scrollToEnd = (page) =>
  page.locator('.ff-content').evaluate((el) => { el.scrollTop = el.scrollHeight; });

test('pages stream in on scroll until the folder is complete', async ({ page }) => {
  const folder = seedFolder(450);
  await openFolder(page, folder);

  await expect(cards(page)).toHaveCount(200);
  await expect(page.locator('.ff-load-progress .ff-load-more-meta')).toContainText('200');
  // Auto-loading replaces the manual button.
  await expect(page.locator('.ff-load-progress .ff-load-more-btn')).toBeHidden();

  await scrollToEnd(page);
  await expect(cards(page)).toHaveCount(400, { timeout: 15_000 });
  await scrollToEnd(page);
  await expect(cards(page)).toHaveCount(450, { timeout: 15_000 });

  await expect(page.locator('.ff-load-done')).toContainText('450');
  await expect(page.locator('.ff-load-progress')).toHaveCount(0);
  await expect(cardByName(page, 'f0449.png')).toBeAttached();
});

test('a failed page pauses auto-loading and Retry resumes it', async ({ page }) => {
  const folder = seedFolder(260);
  await openFolder(page, folder);
  await expect(cards(page)).toHaveCount(200);

  await page.route('**/api/fm/list?*cursor=*', (route) =>
    route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ data: null, error: 'boom' }) }),
  );
  await scrollToEnd(page);
  const err = page.locator('.ff-load-more-error');
  await expect(err).toBeVisible({ timeout: 10_000 });
  await expect(cards(page)).toHaveCount(200);

  await page.unroute('**/api/fm/list?*cursor=*');
  await err.getByRole('button').click();
  await expect(cards(page)).toHaveCount(260, { timeout: 15_000 });
  await expect(page.locator('.ff-load-done')).toContainText('260');
});

test('thumbnails fade in once loaded; back-to-top returns to the start', async ({ page }) => {
  const folder = seedFolder(220);
  await openFolder(page, folder);

  await expect(page.locator('.file-card .thumb-img img.is-loaded').first()).toBeAttached({ timeout: 15_000 });

  const back = page.locator('.ff-back-top');
  await expect(back).not.toHaveClass(/show/);
  await scrollToEnd(page);
  await expect(back).toHaveClass(/show/);
  await back.click();
  await expect.poll(() => page.locator('.ff-content').evaluate((el) => el.scrollTop), { timeout: 5_000 }).toBe(0);
  await expect(back).not.toHaveClass(/show/);
});

test('switching folders mid-load never mixes in the old folder', async ({ page }) => {
  const a = seedFolder(230);
  const b = seedFolder(5);
  await openFolder(page, a);
  await expect(cards(page)).toHaveCount(200);

  // Hold the next page of A, scroll to request it, then leave for B.
  let release: () => void = () => {};
  const held = new Promise<void>((r) => { release = r; });
  let delivered: () => void = () => {};
  const done = new Promise<void>((r) => { delivered = r; });
  await page.route('**/api/fm/list?*cursor=*', async (route) => {
    await held;
    await route.continue();
    delivered();
  }, { times: 1 });
  await scrollToEnd(page);
  await expect(page.locator('.ff-more-skeleton')).toBeVisible({ timeout: 10_000 });

  // Up to the root via the breadcrumb, then into B — all inside the same app.
  await page.locator('.ff-breadcrumb button').first().click();
  await cardByName(page, b).dblclick();
  await expect(cards(page)).toHaveCount(5, { timeout: 15_000 });
  // Now let A's page arrive — late, for a folder the user already left.
  release();
  await done;
  await page.waitForTimeout(500);
  await expect(cards(page)).toHaveCount(5);
  await expect(cardByName(page, 'f0200.png')).toHaveCount(0);
});
