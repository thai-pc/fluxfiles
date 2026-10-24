import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, type Page, type Frame, type FrameLocator } from '@playwright/test';

const here = dirname(fileURLToPath(import.meta.url));
const rig = join(here, '..');
export const BASE = 'http://localhost:8390';
export const HOOKS = 'http://localhost:8393';
export const BASE_OVERRIDES = JSON.parse(readFileSync(join(rig, 'fixtures', 'overrides.json'), 'utf8'));

/** wp-cli inside the WordPress container. */
export function wp(...args: string[]): string {
  return execFileSync('docker', ['compose', '-p', 'ff-live-wp', '-f', 'docker-compose.wordpress.yml', 'exec', '-T', 'wp', 'wp', ...args],
    { cwd: rig }).toString();
}

/** Retune the token overrides the mu-plugin feeds to `fluxfiles_token_overrides`. */
export function setOverrides(extra: Record<string, unknown> = {}) {
  wp('option', 'update', 'fluxfiles_e2e_overrides', JSON.stringify({ ...BASE_OVERRIDES, ...extra }));
}

export function phpBytes(code: string, ...args: string[]): Buffer {
  return execFileSync('php', ['-r', code, ...args]);
}

/** A real, uniquely-coloured PNG (unique bytes → no duplicate-upload short-circuit). */
export function pngFile(name: string, w = 240, h = 160) {
  const c = () => String(Math.floor(Math.random() * 200) + 30);
  const buf = phpBytes(
    '$im=imagecreatetruecolor((int)$argv[1],(int)$argv[2]);imagefill($im,0,0,imagecolorallocate($im,(int)$argv[3],(int)$argv[4],(int)$argv[5]));' +
    'imagefilledellipse($im,(int)$argv[1]/2,(int)$argv[2]/2,(int)$argv[1]/2,(int)$argv[2]/2,imagecolorallocate($im,250,240,90));imagepng($im);',
    String(w), String(h), c(), c(), c());
  return { name, mimeType: 'image/png', buffer: buf };
}

/** A PNG with large black text on white — readable by Tesseract. */
export function textPng(name: string, text: string) {
  const buf = phpBytes(
    '$s=imagecreatetruecolor(9*strlen($argv[1])+20,30);imagefill($s,0,0,imagecolorallocate($s,255,255,255));' +
    'imagestring($s,5,10,7,$argv[1],imagecolorallocate($s,0,0,0));' +
    '$b=imagecreatetruecolor(imagesx($s)*5,imagesy($s)*5);imagecopyresampled($b,$s,0,0,0,0,imagesx($b),imagesy($b),imagesx($s),imagesy($s));imagepng($b);',
    text);
  return { name, mimeType: 'image/png', buffer: buf };
}

export function textFile(name: string, body: string, mimeType = 'text/plain') {
  return { name, mimeType, buffer: Buffer.from(body) };
}

export async function loginAdmin(page: Page) {
  for (let attempt = 0; attempt < 3; attempt++) {
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('#wpadminbar').count()) return;
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
    await page.goto(`${BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('#wpadminbar').count()) return;
  }
  throw new Error('WordPress admin login failed');
}

export type Manager = { fm: FrameLocator; frame: Frame };

/** The [fluxfiles] shortcode page: the real plugin embed (SDK → iframe → REST proxy). */
export async function openFiles(page: Page): Promise<Manager> {
  await page.goto(`${BASE}/files/`);
  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 30_000 });
  const frame = page.frames().find((f) => f.url().includes('/public/'));
  if (!frame) throw new Error('FluxFiles iframe not found');
  // The token arrives by postMessage after boot — wait until the app has it.
  await frame.waitForFunction(() => {
    const el = document.querySelector('.ff-app') as any;
    const c = el?._x_dataStack?.[0];
    return !!(c && c.token);
  }, null, { timeout: 20_000 });
  return { fm, frame };
}

/** Call the API from inside the iframe through the app's own fetch wrapper (Bearer + WP REST base). */
export async function api(m: Manager, method: string, path: string, body?: unknown): Promise<any> {
  return m.frame.evaluate(async ({ method, path, body }) => {
    const c = (document.querySelector('.ff-app') as any)._x_dataStack[0];
    return c.api(method, path, body);
  }, { method, path, body });
}

/** Same, but return {ok, data|code} instead of throwing (for expected refusals). */
export async function apiTry(m: Manager, method: string, path: string, body?: unknown): Promise<{ ok: boolean; data?: any; code?: string; status?: number; message?: string }> {
  return m.frame.evaluate(async ({ method, path, body }) => {
    const c = (document.querySelector('.ff-app') as any)._x_dataStack[0];
    try { return { ok: true, data: await c.api(method, path, body) }; }
    catch (e: any) { return { ok: false, code: e.code, status: e.status, message: String(e.message || e) }; }
  }, { method, path, body });
}

/** The REST endpoint the iframe is talking to (proves it is the WP proxy, not a core server). */
export async function endpoint(m: Manager): Promise<string> {
  return m.frame.evaluate(() => (document.querySelector('.ff-app') as any)._x_dataStack[0].joinUrl('/api/fm/list'));
}

export function cardByName(scope: FrameLocator, name: string) {
  return scope.locator(`.file-card:has(.fname:text-is(${JSON.stringify(name)}))`);
}

export async function upload(m: Manager, file: { name: string; mimeType: string; buffer: Buffer }) {
  await m.fm.locator('input[type=file]').first().setInputFiles(file);
}

export async function uploadAndSee(m: Manager, file: { name: string; mimeType: string; buffer: Buffer }) {
  await upload(m, file);
  await expect(cardByName(m.fm, file.name)).toBeVisible({ timeout: 30_000 });
}

/**
 * Right-click a card. The shortcode iframe sits inside the theme's content column,
 * which is ≤ 768px wide, so the UI shows its mobile action sheet instead of the
 * desktop context menu — both carry the same module entries.
 */
export async function contextMenu(m: Manager, name: string) {
  await cardByName(m.fm, name).click({ button: 'right' });
  const menu = m.fm.locator('.ff-context-menu:visible, .ff-action-sheet:visible').first();
  await expect(menu).toBeVisible();
  return menu;
}

/**
 * Tick "Audit" under Settings → FluxFiles → Default Permissions, the way an operator
 * does. That option is the ceiling every embed token is intersected with, so without
 * it not even an Administrator's token carries `audit` (Activity log, audit export,
 * fixity, legal hold, retention all check it). Starts from the activation default.
 */
export async function grantAuditViaSettings(page: Page) {
  wp('option', 'update', 'fluxfiles_default_perms', '["read","write","delete"]', '--format=json');
  await loginAdmin(page);
  await page.goto(`${BASE}/wp-admin/options-general.php?page=fluxfiles`);
  const audit = page.locator('input[name="fluxfiles_default_perms[]"][value="audit"]');
  await expect(audit).toHaveCount(1);
  await audit.check();
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#submit').click()]);
  await expect(page.locator('#setting-error-settings_updated, .notice-success').first()).toBeVisible();
  expect(JSON.parse(wp('option', 'get', 'fluxfiles_default_perms', '--format=json'))).toContain('audit');
}

export async function listNames(m: Manager, disk = 'local', path = ''): Promise<string[]> {
  const res = await api(m, 'GET', `/api/fm/list?disk=${disk}&path=${encodeURIComponent(path)}&limit=500`);
  return (res.items as Array<{ name: string }>).map((i) => i.name);
}

export async function hookEvents(): Promise<Array<{ body: string; headers: Record<string, string> }>> {
  return (await fetch(`${HOOKS}/events`)).json();
}
