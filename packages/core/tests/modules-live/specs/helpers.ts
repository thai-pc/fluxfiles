import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, type Page, type FrameLocator } from '@playwright/test';

const here = dirname(fileURLToPath(import.meta.url));
const coreDir = join(here, '..', '..', '..');
export const SECRET = readFileSync(join(here, '..', '.stage', 'secret.txt'), 'utf8').trim();
export const HOOKS = 'http://localhost:8193';

/** A JWT for the live stack (same FLUXFILES_SECRET as the container). */
export function token(user: string, claims: Record<string, unknown> = {}, perms = ['read', 'write', 'delete']): string {
  const php =
    `require '${coreDir}/embed.php';` +
    `$_ENV['FLUXFILES_SECRET']=$argv[1];` +
    `echo fluxfiles_token(['user'=>$argv[2],'perms'=>json_decode($argv[3],true),'disks'=>['local','s3'],'ttl'=>3600,'claims'=>json_decode($argv[4],true)]);`;
  return execFileSync('php', ['-r', php, SECRET, user, JSON.stringify(perms), JSON.stringify(claims)]).toString().trim();
}

/** Run PHP with GD on the host and return its stdout bytes (test fixtures). */
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

export async function openManager(page: Page, jwt: string, path = '') {
  await page.goto(`/public/index.html?token=${jwt}&disk=local${path ? `&path=${encodeURIComponent(path)}` : ''}`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 20_000 });
}

export function cardByName(scope: Page | FrameLocator, name: string) {
  return scope.locator(`.file-card:has(.fname:text-is(${JSON.stringify(name)}))`);
}

export async function upload(page: Page, file: { name: string; mimeType: string; buffer: Buffer }) {
  await page.locator('input[type=file]').first().setInputFiles(file);
}

export async function uploadAndSee(page: Page, file: { name: string; mimeType: string; buffer: Buffer }) {
  await upload(page, file);
  await expect(cardByName(page, file.name)).toBeVisible({ timeout: 20_000 });
}

export async function contextMenu(page: Page, name: string) {
  await cardByName(page, name).click({ button: 'right' });
  await expect(page.locator('.ff-context-menu')).toBeVisible();
  return page.locator('.ff-context-menu');
}

/** Call the core API from inside the page (the app's own token + fetch wrapper). */
export async function api(page: Page, method: string, path: string, body?: unknown) {
  return page.evaluate(async ({ method, path, body }) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return c.api(method, path, body);
  }, { method, path, body });
}

export async function hookEvents(): Promise<Array<{ body: string; headers: Record<string, string> }>> {
  return (await fetch(`${HOOKS}/events`)).json();
}
