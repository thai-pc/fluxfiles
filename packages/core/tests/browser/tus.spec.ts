import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager, uploadFile, cardByName } from './helpers';

// Large uploads on a local disk go through resumable tus. The built-in test
// server keeps PHP's default upload_max_filesize (2 MB), so a 9 MB file can
// ONLY land via tus — this is a real end-to-end proof, not a mock.
test('a 9 MB upload lands via tus (PATCH chunks), bypassing the PHP upload limit', async ({ page }) => {
  const prefix = `tus-ui-${Date.now()}`;
  await openManager(page, mintTokenWithClaims({ prefix, max_upload: 50 }));
  const methods: string[] = [];
  page.on('request', (r) => { if (r.url().includes('/api/fm/tus')) methods.push(r.method()); });

  const big = Buffer.alloc(9 * 1024 * 1024, 7);
  await uploadFile(page, { name: 'video-raw.bin', mimeType: 'application/octet-stream', buffer: big });
  await expect(cardByName(page, 'video-raw.bin')).toBeVisible({ timeout: 30_000 });
  expect(methods[0]).toBe('POST');
  expect(methods.filter((m) => m === 'PATCH').length).toBeGreaterThanOrEqual(2); // 5 MB chunks
});

test('no tus endpoint (proxy / older core) → falls back to a plain upload', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ prefix: `tus-fb-${Date.now()}`, max_upload: 50 }));
  await page.route('**/api/fm/tus', (route) => route.fulfill({ status: 404, body: '' }));
  let plain = 0;
  await page.route('**/api/fm/upload', (route) => {
    plain++;
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { key: 'f.bin', name: 'f.bin', size: 1, url: '/x' }, error: null }) });
  });
  await uploadFile(page, { name: 'f.bin', mimeType: 'application/octet-stream', buffer: Buffer.alloc(9 * 1024 * 1024, 1) });
  await expect.poll(() => plain, { timeout: 15_000 }).toBe(1);
});
