import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync, utimesSync } from 'node:fs';
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

// Sort + type filter run on the SERVER over the whole folder, so "newest first"
// or "videos only" is right before the matching rows were ever loaded.

test('newest-first finds the newest file in the folder, not just on the loaded page', async ({ page }) => {
  const folder = seedFolder(450);
  // Everything is old except f0420 — far past the first page in name order.
  const dir = join(uploadsDir, folder);
  for (let i = 0; i < 450; i++) {
    utimesSync(join(dir, `f${String(i).padStart(4, '0')}.png`), 1_600_000_000 + i, 1_600_000_000 + i);
  }
  utimesSync(join(dir, 'f0420.png'), 1_900_000_000, 1_900_000_000);
  // The UI's default sort is newest first — so the very first page already leads
  // with f0420, although it sits on page 3 in name order.
  await openFolder(page, folder);
  await expect(cards(page)).toHaveCount(200);
  await expect(cards(page).first()).toContainText('f0420.png');
  await expect(cards(page).nth(1)).toContainText('f0449.png');

  // Switch to name: the server re-sorts the whole folder (f0420 is no longer here).
  let list = page.waitForResponse((r) => r.url().includes('/api/fm/list') && r.url().includes('sort=name'));
  await page.locator('.ff-sort-dd .ff-dd-btn').click();
  await page.locator('.ff-sort-dd .ff-dd-menu button').filter({ hasText: 'Name' }).click();
  await list;
  await expect(cards(page).first()).toContainText('f0000.png');
  await expect(cardByName(page, 'f0420.png')).toHaveCount(0);

  // And back to date (newest first again).
  list = page.waitForResponse((r) => r.url().includes('/api/fm/list') && r.url().includes('sort=date'));
  await page.locator('.ff-sort-dd .ff-dd-btn').click();
  await page.locator('.ff-sort-dd .ff-dd-menu button').filter({ hasText: 'Date' }).click();
  await list;
  await expect(cards(page).first()).toContainText('f0420.png');
});

test('a type filter is applied server-side: one video in 450 images shows at once', async ({ page }) => {
  const folder = seedFolder(450);
  writeFileSync(join(uploadsDir, folder, 'zz-clip.mp4'), Buffer.from('not really a video'));
  await openFolder(page, folder);

  const list = page.waitForResponse((r) => r.url().includes('/api/fm/list') && r.url().includes('type=video'));
  await page.locator('.ff-filter-dd .ff-dd-btn').click();
  await page.locator('.ff-filter-dd .ff-dd-menu button').filter({ hasText: 'Video' }).click();
  await list;
  await expect(cards(page)).toHaveCount(1);
  await expect(cards(page).first()).toContainText('zz-clip.mp4');
  // One request answered it: no paging through the 450 images to find it.
  await expect(page.locator('.ff-load-progress')).toHaveCount(0);
});

test('a fully-loaded folder re-sorts in place, with no extra request', async ({ page }) => {
  const folder = seedFolder(12);
  await openFolder(page, folder);
  await expect(cards(page)).toHaveCount(12);
  let listCalls = 0;
  page.on('request', (r) => { if (r.url().includes('/api/fm/list')) listCalls++; });
  const sortBy = async (label: string) => {
    await page.locator('.ff-sort-dd .ff-dd-btn').click();
    await page.locator('.ff-sort-dd .ff-dd-menu button').filter({ hasText: label }).click();
  };
  await sortBy('Name');                       // date → name, ascending
  await expect(cards(page).first()).toContainText('f0000.png');
  await sortBy('Name');                       // same key → descending
  await expect(cards(page).first()).toContainText('f0011.png');
  expect(listCalls).toBe(0);
});

// Review follow-ups: a page is always asked for in the order its cursor came from.

test('a sort picked during a search applies when the search is cleared', async ({ page }) => {
  const folder = seedFolder(450);
  const dir = join(uploadsDir, folder);
  for (let i = 0; i < 450; i++) {
    utimesSync(join(dir, `f${String(i).padStart(4, '0')}.png`), 1_600_000_000 + i, 1_600_000_000 + i);
  }
  await openFolder(page, folder);
  await expect(cards(page).first()).toContainText('f0449.png');   // newest first

  const search = page.locator('input[x-model="searchQuery"]').first();
  const searched = page.waitForResponse((r) => r.url().includes('/api/fm/search?'));
  await search.fill('f04');
  await searched;
  await page.locator('.ff-sort-dd .ff-dd-btn').click();
  await page.locator('.ff-sort-dd .ff-dd-menu button').filter({ hasText: 'Name' }).click();

  const reload = page.waitForResponse((r) => r.url().includes('/api/fm/list') && r.url().includes('sort=name') && !r.url().includes('cursor='));
  await search.fill('');
  await reload;
  await expect(cards(page).first()).toContainText('f0000.png');
});

test('a sort changed while the folder is still loading is applied once it lands', async ({ page }) => {
  const folder = seedFolder(450);
  await openManager(page, mintToken());
  // Hold the folder's first page (date order), switch to name meanwhile.
  let release: () => void = () => {};
  const held = new Promise<void>((r) => { release = r; });
  await page.route('**/api/fm/list?*sort=date*', async (route) => {
    if (route.request().url().includes(encodeURIComponent(folder))) await held;
    await route.continue();
  });
  await cardByName(page, folder).dblclick();
  await page.locator('.ff-sort-dd .ff-dd-btn').click();
  await page.locator('.ff-sort-dd .ff-dd-menu button').filter({ hasText: 'Name' }).click();
  const byName = page.waitForResponse((r) => r.url().includes('/api/fm/list') && r.url().includes('sort=name'));
  release();
  await byName;
  await page.unroute('**/api/fm/list?*sort=date*');
  await expect(cards(page).first()).toContainText('f0000.png');
  // And the next page continues in NAME order.
  const next = page.waitForResponse((r) => r.url().includes('cursor=') );
  await scrollToEnd(page);
  expect((await next).url()).toContain('sort=name');
});

test('a stale cursor (row deleted meanwhile) reloads the folder instead of erroring', async ({ page }) => {
  const folder = seedFolder(260);
  await openFolder(page, folder);
  await expect(cards(page)).toHaveCount(200);
  await page.route('**/api/fm/list?*cursor=*', (route) =>
    route.fulfill({ status: 409, contentType: 'application/json',
      body: JSON.stringify({ data: null, error: 'The listing changed; reload it', error_code: 'cursor_stale' }) }), { times: 1 });
  const reload = page.waitForResponse((r) => r.url().includes('/api/fm/list') && !r.url().includes('cursor='));
  await scrollToEnd(page);
  await reload;
  await expect(page.locator('.ff-load-more-error')).toHaveCount(0);
  await scrollToEnd(page);
  await expect(cards(page)).toHaveCount(260, { timeout: 15_000 });
});
