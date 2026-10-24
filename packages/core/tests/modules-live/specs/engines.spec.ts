import { test, expect } from '@playwright/test';
import { token, openManager, upload, uploadAndSee, cardByName, contextMenu, pngFile, textPng, textFile, api } from './helpers';

// The modules that drive a real engine: AI Vision (self-hosted model stand-in),
// OCR (Tesseract), Virus (ClamAV), DLP (Microsoft Presidio), Backup (S3 on
// LocalStack), C2PA (c2patool). Nothing here is mocked in the browser.

// The EICAR test string, assembled at runtime so this file never matches itself.
const EICAR = ['X5O!P%@AP[4\\PZX54(P^)7CC)7}', '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'].join('');

async function listNames(page: import('@playwright/test').Page, disk = 'local', path = '') {
  const res = await api(page, 'GET', `/api/fm/list?disk=${disk}&path=${encodeURIComponent(path)}&limit=500`);
  return ((res as { items: Array<{ name: string; width?: number; height?: number }> }).items);
}

test('AI Vision: remove background and upscale write new images next to the original', async ({ page }) => {
  const user = `ai-${Date.now()}`;
  await openManager(page, token(user, { allow_ai_vision: true, prefix: user }));
  const f = pngFile('portrait.png', 200, 120);
  await uploadAndSee(page, f);

  await cardByName(page, f.name).click();
  await page.getByRole('button', { name: 'AI Vision', exact: true }).click();
  const modal = page.locator('.ff-aivision-modal');
  const result = modal.locator('.ff-activity-state').filter({ hasText: 'Saved as' });
  await modal.getByRole('button', { name: 'Remove background' }).click();
  await expect(result).toBeVisible({ timeout: 30_000 });
  const first = await result.textContent();
  await modal.getByRole('button', { name: 'Upscale' }).click();
  await expect(result).not.toHaveText(first ?? '', { timeout: 30_000 });

  const names = (await listNames(page)).map((i) => i.name);
  expect(names).toEqual(expect.arrayContaining(['portrait-nobg.png', 'portrait-upscaled.png']));
  // Read the stored result's PNG header: the upscale really is 2× the original.
  const dims = await page.evaluate(async () => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const item = c.files.find((x: any) => x.name === 'portrait-upscaled.png') ?? (await c.api('GET', '/api/fm/list?disk=local&path=&limit=500')).items.find((x: any) => x.name === 'portrait-upscaled.png');
    const buf = new DataView(await (await fetch(item.url)).arrayBuffer());
    return [buf.getUint32(16), buf.getUint32(20)];
  });
  expect(dims).toEqual([400, 240]);
});

test('OCR: Tesseract reads the text in an image; it can be saved as the caption', async ({ page }) => {
  const user = `ocr-${Date.now()}`;
  await openManager(page, token(user, { allow_ocr: true, prefix: user }));
  const f = textPng('scan.png', 'INVOICE 4471 PAID');
  await uploadAndSee(page, f);

  const menu = await contextMenu(page, f.name);
  await menu.getByText('Extract text (OCR)').click();
  const dialog = page.getByRole('dialog', { name: 'Extract text (OCR)' });
  await dialog.getByRole('button', { name: 'Extract text' }).click();
  // Words, not digits: the fixture is a scaled-up bitmap font, where 4471 can come
  // back as 44/71 — the point is that Tesseract really ran on the stored image.
  await expect(dialog.locator('textarea')).toHaveValue(/INVOICE[\s\S]*PAID/i, { timeout: 30_000 });
  await dialog.getByRole('button', { name: 'Save as caption' }).click();
  await expect(page.getByText("Saved to the file's caption")).toBeVisible();
  const meta = await api(page, 'GET', `/api/fm/metadata?disk=local&key=${f.name}`);
  expect((meta as { caption: string }).caption).toMatch(/INVOICE[\s\S]*PAID/i);
});

test('Virus: ClamAV blocks an EICAR upload, lets a clean one through, and scans on demand', async ({ page }) => {
  const user = `av-${Date.now()}`;
  await openManager(page, token(user, { allow_virus_scan: true, prefix: user }, ['read', 'write', 'delete', 'audit']));
  await expect(page.locator('.ff-scan-note')).toContainText('Uploads are scanned for viruses');

  await upload(page, textFile('eicar.txt', EICAR));
  await expect(page.locator('.ff-toast')).toContainText('blocked by the virus scanner', { timeout: 30_000 });
  await expect(cardByName(page, 'eicar.txt')).toHaveCount(0);
  // The refusal is audited under the tenant's own key, so its Activity log shows it.
  const log = await api(page, 'GET', '/api/fm/audit?limit=50') as Array<{ action: string; file_key?: string }>;
  expect(log.some((r) => r.action === 'virus_blocked' && String(r.file_key ?? '').endsWith('eicar.txt'))).toBe(true);

  const clean = pngFile(`clean-${Date.now()}.png`);
  await uploadAndSee(page, clean);
  const menu = await contextMenu(page, clean.name);
  await menu.getByText('Scan for viruses').click();
  await expect(page.getByRole('dialog', { name: 'Virus scan' }).locator('.ff-scan-clean')).toHaveText('No threats found', { timeout: 30_000 });
});

test('DLP: Presidio blocks a file with a credit-card number; plain text passes', async ({ page }) => {
  const user = `dlp-${Date.now()}`;
  await openManager(page, token(user, { allow_dlp_scan: true, dlp_entity_types: ['CREDIT_CARD'], prefix: user }));
  await upload(page, textFile('payment.txt', 'Customer card: 4111 1111 1111 1111, exp 12/29.\n'));
  await expect(page.locator('.ff-toast')).toContainText('personal data', { timeout: 60_000 });
  await expect(cardByName(page, 'payment.txt')).toHaveCount(0);
  await uploadAndSee(page, textFile('minutes.txt', 'The meeting moved to Thursday.\n'));
});

test('Backup: a folder is copied to the S3 disk (LocalStack) and lands there', async ({ page }) => {
  const user = `bk-${Date.now()}`;
  await openManager(page, token(user, { allow_backup: true, prefix: user }));
  await page.getByRole('button', { name: 'New folder' }).click();
  await page.locator('[x-ref="newFolderInput"]').fill('reports');
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await cardByName(page, 'reports').dblclick();
  await uploadAndSee(page, textFile('q1.txt', 'Q1\n'));
  await uploadAndSee(page, textFile('q2.txt', 'Q2\n'));
  await openManager(page, token(user, { allow_backup: true, prefix: user }));

  const menu = await contextMenu(page, 'reports');
  await menu.getByText('Back up folder').click();
  const dialog = page.getByRole('dialog', { name: 'Back up folder' });
  await dialog.locator('select').selectOption('s3');
  await dialog.getByRole('button', { name: 'Start backup' }).click();
  await expect(dialog.locator('.ff-scan-clean')).toContainText('Copied 2, skipped 0, errors 0', { timeout: 60_000 });
  const onS3 = (await listNames(page, 's3', 'reports')).map((i) => i.name).sort();
  expect(onS3).toEqual(['q1.txt', 'q2.txt']);
});

test('C2PA: c2patool signs an image; verify then reports the manifest', async ({ page }) => {
  const user = `c2pa-${Date.now()}`;
  await openManager(page, token(user, { allow_c2pa: true, prefix: user }));
  const f = pngFile('original.png');
  await uploadAndSee(page, f);
  const before = await api(page, 'POST', '/api/fm/c2pa', { disk: 'local', path: f.name }) as Record<string, unknown>;
  expect(JSON.stringify(before)).not.toMatch(/FluxFiles modules-live test/);
  const signed = await api(page, 'POST', '/api/fm/c2pa/sign', { disk: 'local', path: f.name }) as Record<string, unknown>;
  const signedPath = String(signed.path ?? signed.key ?? f.name);
  const after = await api(page, 'POST', '/api/fm/c2pa', { disk: 'local', path: signedPath }) as Record<string, unknown>;
  expect(JSON.stringify(after)).toMatch(/FluxFiles modules-live test|c2pa\.published/);
});
