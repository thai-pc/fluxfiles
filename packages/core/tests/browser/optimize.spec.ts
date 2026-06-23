import { test, expect } from '@playwright/test';
import { mintToken, mintTokenWithClaims, openManager } from './helpers';

// Optimize UI (M2). The engine + gate are tested in PHP; here we verify the UI
// wiring: the canOptimize/canOptimizeFile/showBulkOptimize gates, that the
// action POSTs to /api/fm/optimize and shows a savings toast, and that a gate
// error (e.g. 402/501 when the module isn't licensed) surfaces as a toast. The
// endpoint is mocked.

test('canOptimize gates: claim off by default; on with allow_optimize', async ({ page }) => {
  await openManager(page, mintToken()); // default → allow_optimize unset (false)
  expect(await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return { opt: c.canOptimize, img: c.canOptimizeFile({ name: 'a.jpg', key: 'a.jpg', type: 'file' }) };
  })).toEqual({ opt: false, img: false });

  await openManager(page, mintTokenWithClaims({ allow_optimize: true }));
  const r = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return {
      opt: c.canOptimize,
      jpg: c.canOptimizeFile({ name: 'photo.jpg', key: 'photo.jpg', type: 'file' }),
      png: c.canOptimizeFile({ name: 'logo.png', key: 'logo.png', type: 'file' }),
      txt: c.canOptimizeFile({ name: 'a.txt', key: 'a.txt', type: 'file' }),
      dir: c.canOptimizeFile({ name: 'd', key: 'd', type: 'dir' }),
    };
  });
  expect(r).toEqual({ opt: true, jpg: true, png: true, txt: false, dir: false });
});

test('showBulkOptimize: only when 2+ images are selected', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_optimize: true }));
  const vis = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const set = (sel: any[]) => { c.selected = sel; return c.showBulkOptimize; };
    return {
      two: set([{ name: 'a.jpg', key: 'a.jpg', type: 'file' }, { name: 'b.png', key: 'b.png', type: 'file' }]),
      mixed: set([{ name: 'a.jpg', key: 'a.jpg', type: 'file' }, { name: 'n.txt', key: 'n.txt', type: 'file' }]),
      one: set([{ name: 'a.jpg', key: 'a.jpg', type: 'file' }]),
    };
  });
  expect(vis).toEqual({ two: true, mixed: false, one: false });
});

test('optimizeFiles POSTs the path(s) and shows a savings toast + relists', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_optimize: true }));
  let posted: any = null;
  await page.route('**/api/fm/optimize', async (route) => {
    posted = route.request().postDataJSON();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { path: 'a.webp', saved_bytes: 385673, saved_pct: 80 } }) });
  });
  let relisted = false;
  await page.route('**/api/fm/list*', async (route) => { relisted = true; await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }); });

  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).optimizeFiles(['photos/a.jpg']));
  expect(posted).toMatchObject({ disk: 'local', path: 'photos/a.jpg' });
  await expect(page.locator('.ff-toast')).toBeVisible();
  expect(relisted, 'relisted after optimize').toBe(true);
});

test('batch optimizeFiles sends paths[]; a gate error surfaces as a toast', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_optimize: true }));
  let posted: any = null;
  await page.route('**/api/fm/optimize', async (route) => {
    posted = route.request().postDataJSON();
    // Simulate the module not being licensed on this server.
    await route.fulfill({ status: 402, contentType: 'application/json', body: JSON.stringify({ error: 'The optimize module requires a valid license' }) });
  });
  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).optimizeFiles(['a.jpg', 'b.png']));
  expect(posted).toMatchObject({ disk: 'local', paths: ['a.jpg', 'b.png'] });
  await expect(page.locator('.ff-toast')).toContainText('license');
});
