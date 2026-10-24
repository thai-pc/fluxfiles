import { test, expect } from '@playwright/test';
import {
  openHost, upload, uploadAndSee, cardByName, contextMenu, pngFile, textPng, textFile, api, raw, listNames, auditNdjson, newFolder,
} from './helpers';

// The modules that drive a real engine, through the Laravel proxy: AI Vision
// (self-hosted model stand-in), OCR (Tesseract), Virus (ClamAV clamd running in
// the LARAVEL container), DLP (Microsoft Presidio), Backup (S3 on LocalStack),
// C2PA (c2patool). Nothing is mocked in the browser.

// The EICAR test string, assembled at runtime so this file never matches itself.
const EICAR = ['X5O!P%@AP[4\\PZX54(P^)7CC)7}', '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'].join('');

test('AI Vision: remove background, upscale and smart crop write new images next to the original', async ({ page }) => {
  const user = `lai-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_ai_vision: true, prefix: user });
  const f = pngFile('portrait.png', 200, 120);
  await uploadAndSee(fm, f);

  await cardByName(fm, f.name).click();
  await fm.getByRole('button', { name: 'AI Vision', exact: true }).click();
  const modal = fm.locator('.ff-aivision-modal');
  const result = modal.locator('.ff-activity-state').filter({ hasText: 'Saved as' });
  await modal.getByRole('button', { name: 'Remove background' }).click();
  await expect(result).toBeVisible({ timeout: 30_000 });
  const first = await result.textContent();
  await modal.getByRole('button', { name: 'Upscale' }).click();
  await expect(result).not.toHaveText(first ?? '', { timeout: 30_000 });
  const crop = await api(frame, 'POST', '/api/fm/ai-vision', { disk: 'local', path: f.name, op: 'smart_crop' });
  expect(JSON.stringify(crop)).toMatch(/portrait-/);

  const names = (await listNames(frame)).map((i) => i.name);
  expect(names).toEqual(expect.arrayContaining(['portrait-nobg.png', 'portrait-upscaled.png']));
  // Bytes come back through Laravel's gated /api/fm/stream (FLUXFILES_LOCAL_PRIVATE=true):
  // the stored upscale really is 2× the original.
  const dims = await frame.evaluate(async () => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const items = (await c.api('GET', '/api/fm/list?disk=local&path=&limit=500')).items;
    const item = items.find((x: any) => x.name === 'portrait-upscaled.png');
    const res = await fetch(item.url);
    const buf = new DataView(await res.arrayBuffer());
    return { url: item.url, dims: [buf.getUint32(16), buf.getUint32(20)] };
  });
  expect(dims.url).toContain('/api/fm/stream?token=');
  expect(dims.dims).toEqual([400, 240]);
});

test('OCR: Tesseract (in the Laravel container) reads the text; it can be saved as the caption', async ({ page }) => {
  const user = `locr-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_ocr: true, prefix: user });
  const f = textPng('scan.png', 'INVOICE 4471 PAID');
  await uploadAndSee(fm, f);

  const menu = await contextMenu(fm, f.name);
  await menu.getByText('Extract text (OCR)').click();
  const dialog = fm.getByRole('dialog', { name: 'Extract text (OCR)' });
  await dialog.getByRole('button', { name: 'Extract text' }).click();
  await expect(dialog.locator('textarea')).toHaveValue(/INVOICE[\s\S]*PAID/i, { timeout: 30_000 });
  await dialog.getByRole('button', { name: 'Save as caption' }).click();
  await expect(fm.getByText("Saved to the file's caption")).toBeVisible();
  const meta = await api(frame, 'GET', `/api/fm/metadata?disk=local&key=${f.name}`);
  expect((meta as { caption: string }).caption).toMatch(/INVOICE[\s\S]*PAID/i);
});

test('Virus: ClamAV blocks an EICAR upload, lets a clean one through, and scans on demand', async ({ page }) => {
  const user = `lav-${Date.now()}`;
  const { fm } = await openHost(page, user, { allow_virus_scan: true, prefix: user });
  await expect(fm.locator('.ff-scan-note')).toContainText('Uploads are scanned for viruses');

  await upload(fm, textFile('eicar.txt', EICAR));
  await expect(fm.locator('.ff-toast')).toContainText('blocked by the virus scanner', { timeout: 30_000 });
  await expect(cardByName(fm, 'eicar.txt')).toHaveCount(0);

  const clean = pngFile(`clean-${Date.now()}.png`);
  await uploadAndSee(fm, clean);
  const menu = await contextMenu(fm, clean.name);
  await menu.getByText('Scan for viruses').click();
  await expect(fm.getByRole('dialog', { name: 'Virus scan' }).locator('.ff-scan-clean')).toHaveText('No threats found', { timeout: 30_000 });
});

test('Virus: unscannable write paths are refused while scanning is on (chunk/init + presign PUT → 409 virus_unscannable)', async ({ page }) => {
  const user = `lavx-${Date.now()}`;
  const { frame } = await openHost(page, user, { allow_virus_scan: true, prefix: user });
  const chunk = await raw(frame, 'POST', '/api/fm/chunk/init', { disk: 's3', path: 'big.bin', size: 20 * 1024 * 1024 });
  expect(chunk.status).toBe(409);
  expect(chunk.json.error_code).toBe('virus_unscannable');
  const presign = await raw(frame, 'POST', '/api/fm/presign', { disk: 's3', path: 'direct.bin', method: 'PUT', ttl: 600 });
  expect(presign.status).toBe(409);
  expect(presign.json.error_code).toBe('virus_unscannable');
});

test('Virus: an infected content edit is refused; blocked uploads are audited as virus_blocked (core parity)', async ({ page }) => {
  const user = `lavaudit-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, {
    allow_virus_scan: true, allow_audit_export: true, allow_code_edit: true, prefix: user, perms: ['read', 'write', 'delete', 'audit'],
  });
  await upload(fm, textFile('eicar-audit.txt', EICAR));
  await expect(fm.locator('.ff-toast')).toContainText('blocked by the virus scanner', { timeout: 30_000 });
  await uploadAndSee(fm, textFile('config.txt', 'ok\n'));
  const edit = await raw(frame, 'PUT', '/api/fm/content', { disk: 'local', path: 'config.txt', content: EICAR });
  expect(edit.status).toBe(422);
  expect(edit.json.error_code).toBe('virus_detected');
  const blocked = (await auditNdjson(frame)).filter((r) => r.action === 'virus_blocked');
  expect(blocked.length).toBeGreaterThanOrEqual(2);
  expect(JSON.stringify(blocked)).toContain('eicar-audit.txt');
});

test('DLP: Presidio blocks a file with a credit-card number (audited as dlp_blocked); plain text passes', async ({ page }) => {
  const user = `ldlp-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, {
    allow_dlp_scan: true, dlp_entity_types: ['CREDIT_CARD'], allow_audit_export: true, prefix: user, perms: ['read', 'write', 'delete', 'audit'],
  });
  await upload(fm, textFile('payment.txt', 'Customer card: 4111 1111 1111 1111, exp 12/29.\n'));
  await expect(fm.locator('.ff-toast')).toContainText('personal data', { timeout: 60_000 });
  await expect(cardByName(fm, 'payment.txt')).toHaveCount(0);
  await uploadAndSee(fm, textFile('minutes.txt', 'The meeting moved to Thursday.\n'));
  const blocked = (await auditNdjson(frame)).filter((r) => r.action === 'dlp_blocked');
  expect(blocked.length).toBe(1);
  expect(JSON.stringify(blocked[0])).toContain('payment.txt');
  expect(JSON.stringify(blocked[0])).toContain('CREDIT_CARD');
  expect(JSON.stringify(blocked[0])).not.toContain('4111');   // entity TYPE only, never the matched text
});

test('Backup: a folder is copied to the S3 disk (LocalStack) and lands there', async ({ page }) => {
  const user = `lbk-${Date.now()}`;
  let { fm, frame } = await openHost(page, user, { allow_backup: true, prefix: user });
  await newFolder(fm, 'reports');
  await cardByName(fm, 'reports').dblclick();
  await uploadAndSee(fm, textFile('q1.txt', 'Q1\n'));
  await uploadAndSee(fm, textFile('q2.txt', 'Q2\n'));
  ({ fm, frame } = await openHost(page, user, { allow_backup: true, prefix: user }));

  const menu = await contextMenu(fm, 'reports');
  await menu.getByText('Back up folder').click();
  const dialog = fm.getByRole('dialog', { name: 'Back up folder' });
  await dialog.locator('select').selectOption('s3');
  await dialog.getByRole('button', { name: 'Start backup' }).click();
  await expect(dialog.locator('.ff-scan-clean')).toContainText('Copied 2, skipped 0, errors 0', { timeout: 60_000 });
  const onS3 = (await listNames(frame, 's3', 'reports')).map((i) => i.name).sort();
  expect(onS3).toEqual(['q1.txt', 'q2.txt']);
});

test('C2PA: c2patool (in the Laravel container) signs an image; verify then reports the manifest', async ({ page }) => {
  const user = `lc2pa-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_c2pa: true, prefix: user });
  const f = pngFile('original.png');
  await uploadAndSee(fm, f);
  const before = await api(frame, 'POST', '/api/fm/c2pa', { disk: 'local', path: f.name }) as Record<string, unknown>;
  expect(JSON.stringify(before)).not.toMatch(/FluxFiles modules-live test/);
  const signed = await api(frame, 'POST', '/api/fm/c2pa/sign', { disk: 'local', path: f.name }) as Record<string, unknown>;
  const signedPath = String(signed.path ?? signed.key ?? f.name);
  const after = await api(frame, 'POST', '/api/fm/c2pa', { disk: 'local', path: signedPath }) as Record<string, unknown>;
  expect(JSON.stringify(after)).toMatch(/FluxFiles modules-live test|c2pa\.published/);
});
