import { test, expect, type Page, type APIRequestContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pngFile, cardByName, uploadFile } from '../e2e/helpers';

// Real WordPress users per role, against the real plugin build. The role → token mapping
// (FluxFilesPlugin::rolePreset + the capability gate) is what decides what each user may do,
// so this drives it end to end: the UI each role gets, and the server refusing what the UI
// hides. Users are created on the throwaway wp-env site with a fresh random password per run.
const BASE = 'http://localhost:8888';
const PW = 'e2e-' + randomBytes(12).toString('hex');
const ROLES = ['subscriber', 'contributor', 'author', 'editor'] as const;
const user = (role: string) => `fx-e2e-${role}`;

async function login(page: Page, login: string, pass: string) {
  for (let attempt = 0; attempt < 2; attempt++) {
    await page.context().clearCookies();
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', login);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
    await page.goto(`${BASE}/wp-admin/profile.php`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('#wpadminbar').count()) return;
  }
  throw new Error(`login failed for ${login}`);
}

/** The JWT the plugin mints for the logged-in user (cookie + REST nonce, like the UI's refresh). */
async function tokenFor(page: Page): Promise<{ status: number; token: string | null }> {
  await page.goto(`${BASE}/wp-admin/profile.php`, { waitUntil: 'domcontentloaded' });
  return page.evaluate(async () => {
    const nonce = (window as any).wpApiSettings?.nonce;
    const r = await fetch('/?rest_route=/fluxfiles/v1/api/fm/token', { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    return { status: r.status, token: j?.data?.token ?? null };
  });
}
const claims = (t: string) => JSON.parse(Buffer.from(t.split('.')[1], 'base64url').toString('utf8'));

const api = (req: APIRequestContext, token: string) => ({
  upload: (name: string) => req.post(`${BASE}/?rest_route=/fluxfiles/v1/api/fm/upload`, {
    headers: { Authorization: `Bearer ${token}` },
    multipart: { disk: 'local', path: '', file: { name, mimeType: 'image/png', buffer: pngFile(name).buffer } },
  }),
  rename: (path: string, name: string) => req.post(`${BASE}/?rest_route=/fluxfiles/v1/api/fm/rename`, {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, data: { disk: 'local', path, name },
  }),
  trash: (path: string) => req.post(`${BASE}/?rest_route=/fluxfiles/v1/api/fm/trash`, {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, data: { disk: 'local', path },
  }),
  remove: (path: string) => req.delete(`${BASE}/?rest_route=/fluxfiles/v1/api/fm/delete`, {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, data: { disk: 'local', path },
  }),
});

let adminFile = '';

test.beforeAll(async ({ browser, request }) => {
  test.setTimeout(180_000); // each wp-env CLI call costs a few seconds
  // One CLI round trip: create each role's user, or reset its role + password if it exists.
  const script = ROLES.map(r => `wp user get ${user(r)} >/dev/null 2>&1 && wp user update ${user(r)} --user_pass=${PW} --role=${r} >/dev/null || wp user create ${user(r)} ${user(r)}@example.test --role=${r} --user_pass=${PW} >/dev/null`).join('; ');
  execFileSync('npx', ['--yes', '@wordpress/env', 'run', 'cli', 'sh', '-c', script],
    { cwd: dirname(fileURLToPath(import.meta.url)), stdio: 'ignore' });
  // A file uploaded by the admin, which the author must not be able to touch.
  const page = await browser.newPage();
  await login(page, 'admin', 'password');
  const { token } = await tokenFor(page);
  adminFile = `admin-owned-${Date.now()}.png`;
  expect((await api(request, token!).upload(adminFile)).status()).toBe(200);
  await page.close();
});

for (const role of ['subscriber', 'contributor'] as const) {
  test(`${role}: no file manager, no token, no settings`, async ({ page }) => {
    await login(page, user(role), PW);
    const t = await tokenFor(page);
    expect(t.status).toBe(403);
    expect(t.token).toBeNull();
    await page.goto(`${BASE}/files/`);
    await expect(page.locator('iframe')).toHaveCount(0);
    await expect(page.locator('.fluxfiles-error, .fluxfiles-login-required')).toBeVisible();
    const res = await page.goto(`${BASE}/wp-admin/options-general.php?page=fluxfiles`);
    expect(res!.status()).toBe(403);
  });
}

test('author: read + write only, owner_only, no delete button, server refuses delete and touching others', async ({ page, request }) => {
  await login(page, user('author'), PW);
  const { status, token } = await tokenFor(page);
  expect(status).toBe(200);
  const c = claims(token!);
  expect([...c.perms].sort()).toEqual(['read', 'write']);
  expect(c.owner_only).toBe(true);

  const a = api(request, token!);
  const own = `author-${Date.now()}.png`;
  expect((await a.upload(own)).status()).toBe(200);
  expect((await a.rename(own, `renamed-${own}`)).status()).toBe(200);
  expect((await a.trash(`renamed-${own}`)).status()).toBe(403);   // no delete perm
  expect((await a.remove(`renamed-${own}`)).status()).toBe(403);
  expect((await a.rename(adminFile, `hijack-${adminFile}`)).status()).toBe(403); // owner_only

  // The UI hides Delete for this role.
  await page.goto(`${BASE}/files/`);
  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  const card = cardByName(fm, `renamed-${own}`);
  await expect(card).toBeVisible({ timeout: 15_000 });
  await card.click();
  await expect(fm.locator('.detail-btn', { hasText: 'Rename' })).toBeVisible();
  await expect(fm.locator('.detail-btn', { hasText: 'Delete' })).toBeHidden();
  await expect(fm.locator('.tb-btn.danger')).toBeHidden();

  const res = await page.goto(`${BASE}/wp-admin/options-general.php?page=fluxfiles`);
  expect(res!.status()).toBe(403);
});

test('editor: read/write/delete on any file, upload through the UI, still no settings', async ({ page, request }) => {
  await login(page, user('editor'), PW);
  const { status, token } = await tokenFor(page);
  expect(status).toBe(200);
  const c = claims(token!);
  expect([...c.perms].sort()).toEqual(['delete', 'read', 'write']);
  expect(c.owner_only ?? false).toBe(false);

  await page.goto(`${BASE}/files/`);
  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  const own = `editor-${Date.now()}.png`;
  await uploadFile(fm, pngFile(own));
  await expect(cardByName(fm, own)).toBeVisible({ timeout: 15_000 });

  const a = api(request, token!);
  expect((await a.trash(own)).status()).toBe(200);
  expect((await a.trash(adminFile)).status()).toBe(200); // not owner-only

  const res = await page.goto(`${BASE}/wp-admin/options-general.php?page=fluxfiles`);
  expect(res!.status()).toBe(403);
});

test('Settings › Access without Delete: nobody gets a Delete button, and the server agrees', async ({ page, request }) => {
  test.setTimeout(120_000);
  const run = (cmd: string) => execFileSync('npx', ['--yes', '@wordpress/env', 'run', 'cli', 'sh', '-c', cmd],
    { cwd: dirname(fileURLToPath(import.meta.url)), stdio: 'ignore' });
  run(`wp option update fluxfiles_default_perms '["read","write"]' --format=json`);
  try {
    await login(page, user('editor'), PW);
    const { token } = await tokenFor(page);
    expect([...claims(token!).perms].sort()).toEqual(['read', 'write']);
    await page.goto(`${BASE}/files/`);
    const fm = page.frameLocator('iframe');
    await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 25_000 });
    const name = `nodel-${Date.now()}.png`;
    await uploadFile(fm, pngFile(name));
    const card = cardByName(fm, name);
    await expect(card).toBeVisible({ timeout: 15_000 });
    await card.click();
    await expect(fm.locator('.detail-btn', { hasText: 'Rename' })).toBeVisible();
    await expect(fm.locator('.detail-btn', { hasText: 'Delete' })).toBeHidden();
    await expect(fm.locator('.tb-btn.danger')).toBeHidden();
    expect((await api(request, token!).trash(name)).status()).toBe(403);
  } finally {
    run(`wp option update fluxfiles_default_perms '["read","write","delete"]' --format=json`);
  }
});

test('logged out: shortcode asks to log in, REST refuses without a token', async ({ page, request }) => {
  await page.context().clearCookies();
  await page.goto(`${BASE}/files/`);
  await expect(page.locator('.fluxfiles-login-required')).toBeVisible();
  await expect(page.locator('iframe')).toHaveCount(0);
  const r = await request.get(`${BASE}/?rest_route=/fluxfiles/v1/api/fm/list&disk=local&path=`);
  expect(r.status()).toBe(401);
});
