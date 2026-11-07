import { test, expect, Page, FrameLocator } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { deflateSync } from 'node:zlib';
import { TEST_SECRET, CORE_PORT, CK5_PORT, CORE_ENDPOINT } from './real/env';

// REAL editors + REAL adapters + the REAL FluxFiles picker (a live core serving the
// iframe). Unlike the stubbed specs next to this file, nothing about the SDK or the
// picker is faked: the toolbar button opens the iframe, we pick a seeded file in it,
// and assert what lands in the editor.

const coreDir = join(__dirname, '..', '..');
const uploads = join(coreDir, 'storage', 'uploads');
const RUN = Date.now().toString(36);
const IMG = `editor-e2e-${RUN}.png`;
const TXT = `editor-e2e-${RUN}.txt`;
const PNG_W = 200;

function mintToken(): string {
  const php =
    `require '${coreDir}/embed.php';` +
    `$_ENV['FLUXFILES_SECRET']='${TEST_SECRET}';` +
    `echo fluxfiles_token('editors-e2e', ['read','write','delete'], ['local'], '', 50, null, 86400);`;
  return execFileSync('php', ['-r', php]).toString().trim();
}

// Solid-colour RGB PNG built with node's zlib (>= 100px so core also computes srcset).
function png(width = PNG_W, height = 150): Buffer {
  const table = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
  });
  const crc = (b: Buffer) => {
    let c = 0xffffffff;
    for (const x of b) c = table[(c ^ x) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
  };
  const chunk = (type: string, data: Buffer) => {
    const t = Buffer.from(type, 'ascii');
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const sum = Buffer.alloc(4); sum.writeUInt32BE(crc(Buffer.concat([t, data])));
    return Buffer.concat([len, t, data, sum]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 2;
  const row = 1 + width * 3;
  const raw = Buffer.alloc(height * row);
  for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
    const p = y * row + 1 + x * 3; raw[p] = 30; raw[p + 1] = 100; raw[p + 2] = 200;
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0)),
  ]);
}

test.beforeAll(() => {
  mkdirSync(uploads, { recursive: true });
  writeFileSync(join(uploads, IMG), png());
  writeFileSync(join(uploads, TXT), 'hello from the editors e2e\n');
});
test.afterAll(() => {
  rmSync(join(uploads, IMG), { force: true });
  rmSync(join(uploads, TXT), { force: true });
});

/** The FluxFiles picker iframe (the editors themselves also use iframes, so match on origin). */
const picker = (page: Page): FrameLocator => page.frameLocator(`iframe[src*="127.0.0.1:${CORE_PORT}"]`);

/** Double-click `name` in the open picker (== choose it). */
async function pick(page: Page, name: string) {
  const fm = picker(page);
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 20_000 });
  const card = fm.locator(`.file-card:has(.fname:text-is(${JSON.stringify(name)}))`);
  await expect(card).toBeVisible({ timeout: 20_000 });
  await card.dblclick();
  await expect(page.locator('iframe[src*="127.0.0.1:' + CORE_PORT + '"]')).toHaveCount(0, { timeout: 10_000 });
}

const q = () => `?token=${encodeURIComponent(mintToken())}&endpoint=${encodeURIComponent(CORE_ENDPOINT)}`;
const FILE_URL = new RegExp(`/storage/uploads/`);

// What the adapters must produce: an <img> whose src is the file's URL and whose alt is
// the file name; a link whose href is the file's URL for a non-image.
// The URL must be ABSOLUTE on the core origin (a relative one would resolve against
// the host page and 404) and the saved HTML must never carry an expiring
// /img|/stream ?token= URL.
const ORIGIN_RE = `http://127\\.0\\.0\\.1:${CORE_PORT}`;
function expectNoTokenUrl(html: string) {
  expect(html).not.toMatch(/\/(img|stream)(\.php)?\?([^"#]*&)?token=/);
}
function expectImage(html: string, name: string) {
  expect(html).toMatch(new RegExp(`<img[^>]*src="${ORIGIN_RE}/storage/uploads/${name}"`));
  expectNoTokenUrl(html);
  expect(html).toContain(`alt="${name}"`);
}
function expectLink(html: string, name: string) {
  expect(html).toMatch(new RegExp(`<a[^>]*href="${ORIGIN_RE}/storage/uploads/${name}"[^>]*>${name}</a>`));
  expectNoTokenUrl(html);
}

// ── CKEditor 5 (bundled adapter source + real ckeditor5, licenseKey 'GPL') ──────────
const ck5Url = (extra = '') => `http://127.0.0.1:${CK5_PORT}/ckeditor5.html${q()}${extra}`;
const ck5Data = (page: Page) => page.evaluate(() => (window as any).__editor.getData());

test.describe('CKEditor 5', () => {
  test('toolbar button opens the picker; an image is inserted with src + alt', async ({ page }) => {
    await page.goto(ck5Url());
    await page.waitForFunction(() => (window as any).__edReady === true);
    await page.locator('.ck-toolbar .ck-button', { hasText: 'FluxFiles' }).click();
    await pick(page, IMG);
    await expect.poll(() => ck5Data(page)).toContain(`/storage/uploads/${IMG}`);
    await expectImage(await ck5Data(page), IMG);
  });

  test('a non-image file is inserted as a link', async ({ page }) => {
    await page.goto(ck5Url());
    await page.waitForFunction(() => (window as any).__edReady === true);
    await page.locator('.ck-toolbar .ck-button', { hasText: 'FluxFiles' }).click();
    await pick(page, TXT);
    await expect.poll(() => ck5Data(page)).toContain(`/storage/uploads/${TXT}`);
    expectLink(await ck5Data(page), TXT);
  });

  // The adapter's documented behaviour: with ImageInsert loaded, the insert dropdown offers FluxFiles.
  // With the default image.insert.integrations the adapter lists itself, so the
// FluxFiles entry renders without any extra config.
test('dropdown entry appears with the DEFAULT image.insert.integrations', async ({ page }) => {
    await page.goto(ck5Url('&imageInsert=1'));
    await page.waitForFunction(() => (window as any).__edReady === true);
    await page.locator('.ck-toolbar .ck-splitbutton__arrow').click();
    const entry = page.locator('.ck-dropdown__panel .ck-button', { hasText: 'Insert image via FluxFiles' });
    await expect(entry).toBeVisible({ timeout: 3_000 });
    await entry.click();
    await pick(page, IMG);
    await expect.poll(() => ck5Data(page)).toContain(`/storage/uploads/${IMG}`);
    expectImage(await ck5Data(page), IMG);
  });

  // The integration itself works once 'fluxfiles' is listed in image.insert.integrations.
  test('dropdown entry (integration listed in config) opens the picker and inserts the image', async ({ page }) => {
    await page.goto(ck5Url('&imageInsert=1&listIntegration=1'));
    await page.waitForFunction(() => (window as any).__edReady === true);
    await page.locator('.ck-toolbar .ck-splitbutton__arrow').click();
    await page.locator('.ck-dropdown__panel .ck-button', { hasText: 'FluxFiles' }).click();
    await pick(page, IMG);
    await expect.poll(() => ck5Data(page)).toContain(`/storage/uploads/${IMG}`);
    expectImage(await ck5Data(page), IMG);
  });
});

// ── CKEditor 4 (4.22.1, shipped plugin.min.js) ─────────────────────────────────────
test.describe('CKEditor 4', () => {
  const open = async (page: Page) => {
    await page.goto(`/packages/core/tests/editors/real/ckeditor4.html${q()}`);
    await page.waitForFunction(() => (window as any).__edReady === true, null, { timeout: 20_000 });
    await page.locator('.cke_wysiwyg_frame').click(); // real caret, like a user
    await page.locator('a.cke_button__fluxfiles').click();
  };
  const data = (page: Page) => page.evaluate(() => (window as any).CKEDITOR.instances.ed.getData());

  test('image: toolbar button opens the picker and inserts <img src alt>', async ({ page }) => {
    await open(page);
    await pick(page, IMG);
    await expect.poll(() => data(page)).toContain(`/storage/uploads/${IMG}`);
    await expectImage(await data(page), IMG);
  });

  test('non-image: inserted as a link', async ({ page }) => {
    await open(page);
    await pick(page, TXT);
    await expect.poll(() => data(page)).toContain(`/storage/uploads/${TXT}`);
    expectLink(await data(page), TXT);
  });
});

// ── TinyMCE (6.x, shipped plugin.min.js) ───────────────────────────────────────────
test.describe('TinyMCE', () => {
  const open = async (page: Page) => {
    await page.goto(`/packages/core/tests/editors/real/tinymce.html${q()}`);
    await page.waitForFunction(() => (window as any).__edReady === true, null, { timeout: 20_000 });
    await page.locator('.tox-tbtn[aria-label="FluxFiles"]').click();
  };
  const data = (page: Page) => page.evaluate(() => (window as any).tinymce.activeEditor.getContent());

  test('image: toolbar button opens the picker and inserts <img src alt>', async ({ page }) => {
    await open(page);
    await pick(page, IMG);
    await expect.poll(() => data(page)).toContain(IMG);
    await expectImage(await data(page), IMG);
  });

  test('non-image: inserted as a link', async ({ page }) => {
    await open(page);
    await pick(page, TXT);
    await expect.poll(() => data(page)).toContain(TXT);
    expectLink(await data(page), TXT);
  });
});

// ── Summernote (0.8.x lite + jQuery, shipped plugin.min.js) ────────────────────────
test.describe('Summernote', () => {
  const open = async (page: Page) => {
    await page.goto(`/packages/core/tests/editors/real/summernote.html${q()}`);
    await page.waitForFunction(() => (window as any).__edReady === true, null, { timeout: 20_000 });
    await page.locator('.note-editable').click();
    await page.locator('.note-toolbar .note-btn[aria-label="FluxFiles"], .note-toolbar button[data-original-title="FluxFiles"], .note-toolbar button[title="FluxFiles"]').first().click();
  };
  const data = (page: Page) => page.evaluate(() => (window as any).jQuery('#ed').summernote('code'));

  test('image: toolbar button opens the picker and inserts <img src alt>', async ({ page }) => {
    await open(page);
    await pick(page, IMG);
    await expect.poll(() => data(page)).toContain(IMG);
    await expectImage(await data(page), IMG);
  });

  test('non-image: inserted as a link', async ({ page }) => {
    await open(page);
    await pick(page, TXT);
    await expect.poll(() => data(page)).toContain(TXT);
    expectLink(await data(page), TXT);
  });
});
