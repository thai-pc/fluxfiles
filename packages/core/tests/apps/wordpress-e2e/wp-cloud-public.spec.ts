import { test, expect, type Page, type FrameLocator } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pngFile, cardByName, uploadFile } from '../e2e/helpers';

// A PUBLIC S3 bucket on a local LocalStack, so picked cloud files become real Media
// Library attachments served from the bucket (the offload path private buckets refuse).
// Needs:  docker run -d --name ff-localstack -p 4566:4566 -e SERVICES=s3 localstack/localstack:4
// plus a bucket `ff-public` with a public-read policy. Skips when LocalStack is not up.
const BASE = 'http://localhost:8888';
const PUBLIC = 'http://localhost:4566/ff-public';
const CWD = dirname(fileURLToPath(import.meta.url));
const sh = (cmd: string) => execFileSync('npx', ['--yes', '@wordpress/env', 'run', 'cli', 'sh', '-c', cmd], { cwd: CWD, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });

test.describe.configure({ mode: 'serial' });

/** Pick a disk the way a user does: the header pills, or — in a narrow embed — the drawer behind the menu button. */
async function chooseDisk(fm: FrameLocator, label: string) {
  const desktop = fm.locator('.ff-disk-pills-desktop .disk-pill', { hasText: label });
  if (await desktop.isVisible()) { await desktop.click(); return; }
  await fm.getByRole('button', { name: 'Toggle menu' }).click();
  await fm.locator('.ff-disk-pills-mobile .disk-pill', { hasText: label }).click();
}


async function loginAdmin(page: Page) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
  if (await page.locator('#wpadminbar').count()) return;
  await page.waitForTimeout(300);
  await page.evaluate(() => {
    (document.getElementById('user_login') as HTMLInputElement).value = 'admin';
    (document.getElementById('user_pass') as HTMLInputElement).value = 'password';
  });
  await Promise.all([page.waitForURL(/wp-admin/, { waitUntil: 'domcontentloaded' }), page.click('#wp-submit')]);
}

test.beforeAll(async ({ request }) => {
  test.setTimeout(120_000);
  const up = await request.get('http://localhost:4566/_localstack/health').catch(() => null);
  test.skip(!up || !up.ok(), 'LocalStack is not running on :4566');
  sh([
    `wp option update fluxfiles_disks '["local","s3"]' --format=json`,
    'wp option update fluxfiles_s3_bucket ff-public',
    'wp option update fluxfiles_s3_region us-east-1',
    'wp option update fluxfiles_s3_key test',
    'wp option update fluxfiles_s3_secret test',
    // the WordPress container reaches LocalStack through the Docker host
    'wp option update fluxfiles_s3_endpoint http://host.docker.internal:4566',
    'wp option update fluxfiles_s3_visibility public',
    `wp option update fluxfiles_s3_public_url ${PUBLIC}`,
  ].join(' && ') + ' >/dev/null');
});

test.afterAll(() => {
  sh([
    `wp option update fluxfiles_disks '["local"]' --format=json`,
    'wp option delete fluxfiles_s3_bucket fluxfiles_s3_region fluxfiles_s3_key fluxfiles_s3_secret fluxfiles_s3_endpoint fluxfiles_s3_visibility fluxfiles_s3_public_url fluxfiles_delete_storage',
  ].join(' ; ') + ' >/dev/null 2>&1; true');
});

test('settings: "Test S3 connection" reaches the bucket', async ({ page }) => {
  await loginAdmin(page);
  await page.goto(`${BASE}/wp-admin/options-general.php?page=fluxfiles#cloud`);
  await page.click('#ff-tab-cloud');
  await expect(page.locator('#ff-section-fluxfiles_s3 .ff-badge')).toHaveAttribute('data-state', 'ok');
  await page.click('.ff-test-disk[data-disk=s3]');
  const out = page.locator('.ff-test-disk[data-disk=s3] ~ .ff-test-result');
  await expect(out).toHaveAttribute('data-state', 'ok', { timeout: 30_000 });
  await expect(out).toContainText('Connected');
});

let s3Name = '';

test('file manager: upload to S3, the file gets a stable public URL that loads', async ({ page, request }) => {
  await loginAdmin(page);
  await page.goto(`${BASE}/files/`);
  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  await chooseDisk(fm, 'S3');
  s3Name = `pub-${Date.now()}.png`;
  await uploadFile(fm, pngFile(s3Name));
  await expect(cardByName(fm, s3Name)).toBeVisible({ timeout: 20_000 });
  const url = await fm.locator('.ff-app').evaluate((el, n) => {
    const c = (window as any).Alpine.$data(el);
    return ((c.files || []).find((x: any) => x.name === n) || {}).url;
  }, s3Name);
  expect(url).toBe(`${PUBLIC}/${s3Name}`);
  const r = await request.get(url);
  expect(r.status()).toBe(200);
  expect(r.headers()['content-type']).toContain('image/png');
});

let attachId = 0;

test('native picker: a public S3 file becomes the featured image, served from the bucket', async ({ page }) => {
  test.setTimeout(120_000);
  await loginAdmin(page);
  await page.goto(`${BASE}/wp-admin/post-new.php`);
  await page.waitForFunction(() => (window as any).wp?.data?.select('core/editor'), null, { timeout: 60_000 });
  await page.evaluate(() => {
    const w = window as any;
    try { w.wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch (e) { /* none */ }
    w.wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document');
  });
  await page.getByRole('button', { name: 'Set featured image' }).click();
  await page.locator('.media-modal:visible .fluxfiles-from-btn').click();
  const fm = page.frameLocator('#fluxfiles-native-modal iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  await chooseDisk(fm, 'S3');
  await cardByName(fm, s3Name).click({ timeout: 20_000 });
  await fm.locator('button.detail-btn', { hasText: 'Select File' }).click();
  await page.waitForFunction(() => (window as any).wp.data.select('core/editor').getEditedPostAttribute('featured_media') > 0, null, { timeout: 30_000 });
  attachId = await page.evaluate(() => (window as any).wp.data.select('core/editor').getEditedPostAttribute('featured_media'));
  const media = await page.evaluate(async (id) => {
    const r = await fetch(`/?rest_route=/wp/v2/media/${id}&_fields=source_url,media_details`, { headers: { 'X-WP-Nonce': (window as any).wpApiSettings.nonce } });
    return r.json();
  }, attachId);
  expect(media.source_url).toBe(`${PUBLIC}/${s3Name}`);
  // the panel preview really renders from the bucket
  const img = page.locator('.editor-post-featured-image img');
  await expect(img).toHaveAttribute('src', new RegExp(`^${PUBLIC.replace(/[./]/g, '\\$&')}/`), { timeout: 15_000 });
  await expect.poll(() => img.evaluate((i: HTMLImageElement) => i.naturalWidth)).toBeGreaterThan(0);
});

test('"Delete from storage": off keeps the object, on removes it with the attachment', async ({ page, request }) => {
  test.setTimeout(120_000);
  await loginAdmin(page);
  const del = (id: number) => page.evaluate(async (i) => {
    const r = await fetch(`/?rest_route=/wp/v2/media/${i}&force=true`, { method: 'DELETE', headers: { 'X-WP-Nonce': (window as any).wpApiSettings.nonce } });
    return r.status;
  }, id);
  await page.goto(`${BASE}/wp-admin/profile.php`);

  // OFF (default): the attachment goes, the object stays in the bucket.
  expect(await del(attachId)).toBe(200);
  expect((await request.get(`${PUBLIC}/${s3Name}`)).status()).toBe(200);

  // ON: re-attach the same object, delete again → gone from the bucket.
  sh('wp option update fluxfiles_delete_storage 1 >/dev/null');
  const id2 = await page.evaluate(async (k) => {
    const r = await fetch('/?rest_route=/fluxfiles/v1/api/fm/attach', {
      method: 'POST', headers: { 'X-WP-Nonce': (window as any).wpApiSettings.nonce, 'Content-Type': 'application/json' },
      body: JSON.stringify({ disk: 's3', key: k }),
    });
    return (await r.json())?.data?.id ?? 0;
  }, s3Name);
  expect(id2).toBeGreaterThan(0);
  expect(await del(id2)).toBe(200);
  await expect.poll(async () => (await request.get(`${PUBLIC}/${s3Name}`)).status(), { timeout: 15_000 }).not.toBe(200);
});
