import { test, expect } from '@playwright/test';
import { mintToken, mintTokenWithClaims, openManager, uploadFile, pngFile, cardByName, createFolder } from './helpers';

/**
 * Operator UI for the OCR / Virus scan / Backup paid modules.
 *
 * The harness is a stock `php -S` with no private packages, so the modules are
 * genuinely absent: the "module not installed → 501 + Learn more" path is real.
 * A successful module response is mocked, since that needs a licensed server
 * with tesseract / ClamAV / a second disk.
 */

const CLAIMS = { allow_ocr: true, allow_virus_scan: true, allow_backup: true, disks: ['local', 's3'] };

async function seedImage(page: import('@playwright/test').Page, name: string) {
  // Upload with a plain token: with allow_virus_scan on, the free core (no virus
  // module) correctly refuses every upload — fail-closed.
  await openManager(page, mintToken());
  await uploadFile(page, pngFile(name));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
}

async function rightClick(page: import('@playwright/test').Page, name: string) {
  await cardByName(page, name).click({ button: 'right' });
  await expect(page.locator('.ff-context-menu')).toBeVisible();
}

test('gates: without the claims none of the three entries render', async ({ page }) => {
  const name = `ops-gate-${Date.now()}.png`;
  await seedImage(page, name);
  await rightClick(page, name);
  const menu = page.locator('.ff-context-menu');
  await expect(menu.getByText('Extract text (OCR)')).toBeHidden();
  await expect(menu.getByText('Scan for viruses')).toBeHidden();
  await expect(page.locator('.ff-scan-note')).toBeHidden();
});

test('OCR: extract → text shown → save as caption persists to metadata', async ({ page }) => {
  const name = `ops-ocr-${Date.now()}.png`;
  await seedImage(page, name);
  await openManager(page, mintTokenWithClaims(CLAIMS));
  let posted: Record<string, unknown> | null = null;
  await page.route('**/api/fm/ocr', async (route) => {
    posted = route.request().postDataJSON();
    await route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { path: name, chars: 11, text: 'Hello world\n' }, error: null }) });
  });

  await rightClick(page, name);
  await page.locator('.ff-context-menu').getByText('Extract text (OCR)').click();
  const dialog = page.getByRole('dialog', { name: 'Extract text (OCR)' });
  await expect(dialog).toBeVisible();
  await dialog.locator('select').selectOption('vie');
  await dialog.getByRole('button', { name: 'Extract text' }).click();
  await expect(dialog.locator('textarea')).toHaveValue('Hello world');
  expect(posted).toMatchObject({ disk: 'local', path: name, lang: 'vie' });

  // Real PUT /api/fm/metadata (core) — then read it back.
  await dialog.getByRole('button', { name: 'Save as caption' }).click();
  await expect(page.getByText("Saved to the file's caption")).toBeVisible();
  const meta = await page.evaluate(async (key) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return c.api('GET', `/api/fm/metadata?disk=local&key=${encodeURIComponent(key)}`);
  }, name);
  expect(meta.caption).toBe('Hello world');
});

test('OCR: module absent on free core → real 501 error with a Learn more link', async ({ page }) => {
  const name = `ops-ocr501-${Date.now()}.png`;
  await seedImage(page, name);
  await openManager(page, mintTokenWithClaims(CLAIMS));
  await rightClick(page, name);
  await page.locator('.ff-context-menu').getByText('Extract text (OCR)').click();
  const dialog = page.getByRole('dialog', { name: 'Extract text (OCR)' });
  await dialog.getByRole('button', { name: 'Extract text' }).click();
  await expect(dialog.locator('.ff-new-folder-error')).toBeVisible();
  await expect(dialog.getByRole('link', { name: 'Learn more' })).toBeVisible();
});

test('Virus: uploads-scanned note + on-demand scan reports a threat, then a clean rescan', async ({ page }) => {
  const name = `ops-virus-${Date.now()}.png`;
  await seedImage(page, name);
  await openManager(page, mintTokenWithClaims(CLAIMS));
  await expect(page.locator('.ff-scan-note')).toContainText('Uploads are scanned for viruses');

  let call = 0;
  await page.route('**/api/fm/virus/scan', async (route) => {
    call++;
    const data = call === 1
      ? { path: name, clean: false, threat: 'Eicar-Test-Signature', scanned_at: 1 }
      : { path: name, clean: true, threat: null, scanned_at: 2 };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, error: null }) });
  });

  await rightClick(page, name);
  await page.locator('.ff-context-menu').getByText('Scan for viruses').click();
  const dialog = page.getByRole('dialog', { name: 'Virus scan' });
  await expect(dialog.locator('.ff-scan-infected')).toHaveText('Threat found: Eicar-Test-Signature');
  await dialog.getByRole('button', { name: 'Scan again' }).click();
  await expect(dialog.locator('.ff-scan-clean')).toHaveText('No threats found');
});

test('Backup: folder → destination from the token disks → result summary', async ({ page }) => {
  await openManager(page, mintTokenWithClaims(CLAIMS));
  const folder = `ops-backup-${Date.now()}`;
  await createFolder(page, page, folder);
  let posted: Record<string, unknown> | null = null;
  await page.route('**/api/fm/backup', async (route) => {
    posted = route.request().postDataJSON();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      from: 'local', to: 's3', copied: 3, skipped: 1, errors: 0, bytes: 2048, truncated: false }, error: null }) });
  });

  await rightClick(page, folder);
  await page.locator('.ff-context-menu').getByText('Back up folder').click();
  const dialog = page.getByRole('dialog', { name: 'Back up folder' });
  await expect(dialog.locator('select')).toHaveValue('s3');
  await dialog.getByRole('button', { name: 'Start backup' }).click();
  await expect(dialog.locator('.ff-scan-clean')).toContainText('Copied 3, skipped 1, errors 0');
  expect(posted).toMatchObject({ from_disk: 'local', to_disk: 's3', path: folder, overwrite: false });
});
