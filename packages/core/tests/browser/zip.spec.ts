import { test, expect } from '@playwright/test';
import { mintToken, mintTokenWithClaims, openManager } from './helpers';

// Zip download / extract UI (M3). The zip/extract *logic* is covered by the PHP
// integration + HTTP e2e; here we verify the UI wiring: the canZip/canExtractFile
// gates, that downloadZip POSTs the selection and saves a file, and that
// extractZip POSTs the path + refreshes. The endpoints are mocked.

// A minimal valid empty zip (End-Of-Central-Directory record, 22 bytes).
const EMPTY_ZIP = Buffer.from([0x50, 0x4b, 0x05, 0x06, ...new Array(18).fill(0)]);

test('canZip + canExtractFile gates', async ({ page }) => {
  await openManager(page, mintToken()); // default → allow_zip + allow_download true
  const on = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return {
      canZip: c.canZip,
      zip: c.canExtractFile({ name: 'a.zip', key: 'a.zip', type: 'file' }),
      txt: c.canExtractFile({ name: 'a.txt', key: 'a.txt', type: 'file' }),
      dir: c.canExtractFile({ name: 'd', key: 'd', type: 'dir' }),
    };
  });
  expect(on).toEqual({ canZip: true, zip: true, txt: false, dir: false });

  // preview-only token (allow_download=false) → no zip download.
  await openManager(page, mintTokenWithClaims({ allow_download: false }));
  expect(await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).canZip)).toBe(false);

  // explicit opt-out of zip + extract.
  await openManager(page, mintTokenWithClaims({ allow_zip: false, allow_extract: false }));
  const off = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return { canZip: c.canZip, canExtract: c.canExtractFile({ name: 'a.zip', key: 'a.zip', type: 'file' }) };
  });
  expect(off).toEqual({ canZip: false, canExtract: false });
});

test('downloadZip POSTs the selection and saves a .zip', async ({ page }) => {
  await openManager(page, mintToken());
  let posted: any = null;
  await page.route('**/api/fm/zip', async (route) => {
    posted = route.request().postDataJSON();
    await route.fulfill({
      status: 200,
      contentType: 'application/zip',
      headers: { 'Content-Disposition': 'attachment; filename="bundle.zip"' },
      body: EMPTY_ZIP,
    });
  });

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).downloadZip(['docs', 'photo.jpg'], 'bundle')),
  ]);
  expect(posted).toMatchObject({ disk: 'local', paths: ['docs', 'photo.jpg'], name: 'bundle' });
  expect(download.suggestedFilename()).toBe('bundle.zip');
});

test('downloadZip surfaces a JSON error as a toast (no download)', async ({ page }) => {
  await openManager(page, mintToken());
  await page.route('**/api/fm/zip', (route) =>
    route.fulfill({ status: 413, contentType: 'application/json', body: JSON.stringify({ error: 'Selection is too large to zip' }) }));
  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).downloadZip(['docs'], 'big'));
  await expect(page.locator('.ff-toast')).toContainText('too large');
});

test('extractZip POSTs the path and refreshes the listing', async ({ page }) => {
  await openManager(page, mintToken());
  let posted: any = null;
  await page.route('**/api/fm/extract', async (route) => {
    posted = route.request().postDataJSON();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { extracted: 3, dest: 'pkg', bytes: 100 } }) });
  });
  let relisted = false;
  await page.route('**/api/fm/list*', async (route) => {
    relisted = true;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).extractZip({ name: 'pkg.zip', key: 'pkg.zip', type: 'file' }));
  expect(posted).toMatchObject({ disk: 'local', path: 'pkg.zip' });
  await expect(page.locator('.ff-toast')).toContainText('3');
  expect(relisted, 'listing refetched after extract').toBe(true);
});
