import { execFileSync } from 'node:child_process';
import { expect, type Page, type Frame, type FrameLocator } from '@playwright/test';

export const HOOKS = 'http://localhost:8293';
export const LARAVEL = 'http://localhost:8290';

type Overrides = Record<string, unknown>;
export type Fm = { fm: FrameLocator; frame: Frame };

/**
 * Open the Laravel host page as `user`: <x-fluxfiles :overrides> mints the JWT
 * server-side (FluxFilesManager), loads /fluxfiles.js and iframes /public/index.html,
 * all from Laravel. `overrides` are the adapter's NAMED token overrides
 * (allow_share, prefix, …) — never the raw `claims` escape hatch — so every spec
 * also proves the adapter forwards that claim.
 */
export async function openHost(page: Page, user: string, overrides: Overrides = {}): Promise<Fm> {
  const o = { perms: ['read', 'write', 'delete'], disks: ['local', 's3'], ttl: 3600, ...overrides };
  await page.goto(`/host?u=${encodeURIComponent(user)}&o=${encodeURIComponent(JSON.stringify(o))}`);
  await expect(page.getByTestId('ready-flag')).toHaveText('READY', { timeout: 30_000 });
  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 20_000 });
  const frame = page.frames().find((f) => f.url().includes('/public/index.html'));
  if (!frame) throw new Error('FluxFiles iframe not found');
  return { fm, frame };
}

export function cardByName(scope: FrameLocator, name: string) {
  return scope.locator(`.file-card:has(.fname:text-is(${JSON.stringify(name)}))`);
}

export async function upload(fm: FrameLocator, file: { name: string; mimeType: string; buffer: Buffer }) {
  await fm.locator('input[type=file]').first().setInputFiles(file);
}

export async function uploadAndSee(fm: FrameLocator, file: { name: string; mimeType: string; buffer: Buffer }) {
  await upload(fm, file);
  await expect(cardByName(fm, file.name)).toBeVisible({ timeout: 30_000 });
}

export async function contextMenu(fm: FrameLocator, name: string) {
  await cardByName(fm, name).click({ button: 'right' });
  await expect(fm.locator('.ff-context-menu')).toBeVisible();
  return fm.locator('.ff-context-menu');
}

/** The app's own api() (its token + fetch wrapper) — throws on an error envelope. */
export async function api(frame: Frame, method: string, path: string, body?: unknown): Promise<any> {
  return frame.evaluate(async ({ method, path, body }) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return c.api(method, path, body);
  }, { method, path, body });
}

/** Raw call with the iframe's Bearer: status + parsed JSON (or text) + headers. */
export async function raw(frame: Frame, method: string, path: string, body?: unknown, bearer?: string) {
  return frame.evaluate(async ({ method, path, body, bearer }) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const headers: Record<string, string> = { Authorization: 'Bearer ' + (bearer ?? c.token) };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const res = await fetch(c.joinUrl(path), { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    const text = await res.text();
    let json: any = null;
    try { json = JSON.parse(text); } catch { /* not JSON */ }
    return { status: res.status, json, text, type: res.headers.get('content-type') || '', url: c.joinUrl(path) };
  }, { method, path, body, bearer });
}

export async function listNames(frame: Frame, disk = 'local', path = ''): Promise<Array<{ name: string }>> {
  const res = await api(frame, 'GET', `/api/fm/list?disk=${disk}&path=${encodeURIComponent(path)}&limit=500`);
  return (res as { items: Array<{ name: string }> }).items;
}

export async function hookEvents(): Promise<Array<{ body: string; headers: Record<string, string> }>> {
  return (await fetch(`${HOOKS}/events`)).json();
}

export async function newFolder(fm: FrameLocator, name: string) {
  await fm.getByRole('button', { name: 'New folder' }).click();
  await fm.locator('[x-ref="newFolderInput"]').fill(name);
  await fm.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(cardByName(fm, name)).toBeVisible();
}

/** Lines of this disk's audit export (NDJSON) visible to the iframe's token. */
export async function auditNdjson(frame: Frame, query = ''): Promise<Array<Record<string, any>>> {
  const r = await raw(frame, 'GET', `/api/fm/audit/export?format=ndjson${query}`);
  if (r.status !== 200) throw new Error(`audit export ${r.status}: ${r.text.slice(0, 200)}`);
  return r.text.split('\n').filter(Boolean).map((l) => JSON.parse(l));
}

// ── Fixtures (same generators as the base rig's specs/helpers.ts; host PHP + GD) ──

function phpBytes(code: string, ...args: string[]): Buffer {
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
