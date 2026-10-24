import { test, expect } from '@playwright/test';
import { loginAdmin, openFiles, upload, uploadAndSee, cardByName, contextMenu, pngFile, textPng, textFile, api, listNames } from './helpers';

// The engine-backed modules, through the WP proxy and the shortcode embed: AI Vision
// (self-hosted model stand-in), OCR (Tesseract), Virus (ClamAV), DLP (Presidio),
// Backup (the plugin's S3 disk on LocalStack), C2PA (c2patool). Nothing is mocked.

const EICAR = ['X5O!P%@AP[4\\PZX54(P^)7CC)7}', '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'].join('');

test('AI Vision: remove background + upscale write new images next to the original', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const base = `portrait-${Date.now()}`;
  const f = pngFile(`${base}.png`, 200, 120);
  await uploadAndSee(m, f);

  await cardByName(m.fm, f.name).click();
  await m.fm.getByRole('button', { name: 'AI Vision', exact: true }).click();
  const modal = m.fm.locator('.ff-aivision-modal');
  const result = modal.locator('.ff-activity-state').filter({ hasText: 'Saved as' });
  await modal.getByRole('button', { name: 'Remove background' }).click();
  await expect(result).toBeVisible({ timeout: 30_000 });
  await expect(result).toContainText(`${base}-nobg.png`);
  await modal.getByRole('button', { name: 'Upscale' }).click();
  // Not "text changed": while the request runs the 'Saved as' line is gone, and a
  // negated assertion on a missing element passes immediately.
  await expect(result).toContainText(`${base}-upscaled.png`, { timeout: 30_000 });

  expect(await listNames(m)).toEqual(expect.arrayContaining([`${base}-nobg.png`, `${base}-upscaled.png`]));
  const dims = await m.frame.evaluate(async (name) => {
    const c = (document.querySelector('.ff-app') as any)._x_dataStack[0];
    const item = (await c.api('GET', '/api/fm/list?disk=local&path=&limit=500')).items.find((x: any) => x.name === name);
    const buf = new DataView(await (await fetch(item.url)).arrayBuffer());
    return [buf.getUint32(16), buf.getUint32(20)];
  }, `${base}-upscaled.png`);
  expect(dims).toEqual([400, 240]);
});

test('OCR: Tesseract reads the stored image; saved as the caption', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const f = textPng(`scan-${Date.now()}.png`, 'INVOICE 4471 PAID');
  await uploadAndSee(m, f);
  const menu = await contextMenu(m, f.name);
  await menu.getByText('Extract text (OCR)').click();
  const dialog = m.fm.getByRole('dialog', { name: 'Extract text (OCR)' });
  await dialog.getByRole('button', { name: 'Extract text' }).click();
  await expect(dialog.locator('textarea')).toHaveValue(/INVOICE[\s\S]*PAID/i, { timeout: 30_000 });
  await dialog.getByRole('button', { name: 'Save as caption' }).click();
  await expect(m.fm.getByText("Saved to the file's caption")).toBeVisible();
  const meta = await api(m, 'GET', `/api/fm/metadata?disk=local&key=${f.name}`);
  expect((meta as { caption: string }).caption).toMatch(/INVOICE[\s\S]*PAID/i);
});

test('Virus: ClamAV blocks an EICAR upload through the proxy, passes a clean one, scans on demand', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  await expect(m.fm.locator('.ff-scan-note')).toContainText('Uploads are scanned for viruses');
  const bad = `eicar-${Date.now()}.txt`;
  await upload(m, textFile(bad, EICAR));
  await expect(m.fm.locator('.ff-toast')).toContainText('blocked by the virus scanner', { timeout: 30_000 });
  await expect(cardByName(m.fm, bad)).toHaveCount(0);
  expect(await listNames(m)).not.toContain(bad);

  const clean = pngFile(`clean-${Date.now()}.png`);
  await uploadAndSee(m, clean);
  const menu = await contextMenu(m, clean.name);
  await menu.getByText('Scan for viruses').click();
  await expect(m.fm.getByRole('dialog', { name: 'Virus scan' }).locator('.ff-scan-clean')).toHaveText('No threats found', { timeout: 30_000 });
});

test('DLP: Presidio blocks a credit-card number through the proxy; plain text passes', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const bad = `payment-${Date.now()}.txt`;
  await upload(m, textFile(bad, 'Customer card: 4111 1111 1111 1111, exp 12/29.\n'));
  await expect(m.fm.locator('.ff-toast')).toContainText('personal data', { timeout: 60_000 });
  await expect(cardByName(m.fm, bad)).toHaveCount(0);
  expect(await listNames(m)).not.toContain(bad);
  await uploadAndSee(m, textFile(`minutes-${Date.now()}.txt`, 'The meeting moved to Thursday.\n'));
});

test('Backup: a folder is copied to the plugin-configured S3 disk (LocalStack)', async ({ page }) => {
  await loginAdmin(page);
  let m = await openFiles(page);
  const folder = `reports-${Date.now()}`;
  await m.fm.getByRole('button', { name: 'New folder' }).click();
  await m.fm.locator('[x-ref="newFolderInput"]').fill(folder);
  await m.fm.getByRole('button', { name: 'Create', exact: true }).click();
  await cardByName(m.fm, folder).dblclick();
  await uploadAndSee(m, textFile('q1.txt', `Q1 ${Date.now()}\n`));
  await uploadAndSee(m, textFile('q2.txt', `Q2 ${Date.now()}\n`));
  m = await openFiles(page);

  const menu = await contextMenu(m, folder);
  await menu.getByText('Back up folder').click();
  const dialog = m.fm.getByRole('dialog', { name: 'Back up folder' });
  await dialog.locator('select').selectOption('s3');
  await dialog.getByRole('button', { name: 'Start backup' }).click();
  await expect(dialog.locator('.ff-scan-clean')).toContainText('Copied 2, skipped 0, errors 0', { timeout: 60_000 });
  expect((await listNames(m, 's3', folder)).sort()).toEqual(['q1.txt', 'q2.txt']);
});

test('C2PA: c2patool signs an image through the proxy; verify then reports the manifest', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const f = pngFile(`original-${Date.now()}.png`);
  await uploadAndSee(m, f);
  const before = await api(m, 'POST', '/api/fm/c2pa', { disk: 'local', path: f.name });
  expect(JSON.stringify(before)).not.toMatch(/FluxFiles modules-live test/);
  const signed = await api(m, 'POST', '/api/fm/c2pa/sign', { disk: 'local', path: f.name });
  const signedPath = String(signed.path ?? signed.key ?? f.name);
  const after = await api(m, 'POST', '/api/fm/c2pa', { disk: 'local', path: signedPath });
  expect(JSON.stringify(after)).toMatch(/FluxFiles modules-live test|c2pa\.published/);
});
