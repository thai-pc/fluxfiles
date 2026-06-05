import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page, FrameLocator } from '@playwright/test';
import { expect } from '@playwright/test';
import { TEST_SECRET } from './secret';

const coreDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** Mint a JWT with read/write/delete on the local disk, signed with TEST_SECRET. */
export function mintToken(perms: string[] = ['read', 'write', 'delete']): string {
  const permsPhp = perms.map((p) => `'${p}'`).join(',');
  const php =
    `require '${coreDir}/embed.php';` +
    `$_ENV['FLUXFILES_SECRET']='${TEST_SECRET}';` +
    `echo fluxfiles_token('e2e-user', [${permsPhp}], ['local'], '', 50, null, 86400);`;
  // execFileSync → no shell, so $_ENV is not interpolated away by zsh.
  return execFileSync('php', ['-r', php]).toString().trim();
}

/**
 * A tiny valid 1×1 PNG as an in-memory upload (no temp file needed). Bytes
 * appended after the IEND chunk are ignored by GD/decoders but make each file's
 * hash unique, so the server's duplicate detection doesn't reject repeat uploads.
 */
export function pngFile(name: string) {
  const b64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
  const salt = Buffer.from(`\n<!-- ${name}:${Math.random()} -->`);
  return { name, mimeType: 'image/png', buffer: Buffer.concat([Buffer.from(b64, 'base64'), salt]) };
}

/**
 * A real multi-pixel PNG (via GD) so the crop UI has natural dimensions to work
 * with. A random fill colour makes the bytes — and therefore the hash — unique,
 * so duplicate detection doesn't reject it.
 */
export function imageFile(name: string, w = 120, h = 80) {
  const c = () => Math.floor(Math.random() * 200) + 20;
  const php =
    `$im=imagecreatetruecolor(${w},${h});` +
    `imagefilledrectangle($im,0,0,${w - 1},${h - 1},imagecolorallocate($im,${c()},${c()},${c()}));` +
    `ob_start();imagepng($im);echo base64_encode(ob_get_clean());`;
  const b64 = execFileSync('php', ['-r', php]).toString().trim();
  return { name, mimeType: 'image/png', buffer: Buffer.from(b64, 'base64') };
}

/** A `.file-card` whose visible filename is exactly `name` (works for files and folders). */
export function cardByName(scope: Page | FrameLocator, name: string) {
  return scope.locator(`.file-card:has(.fname:text-is(${JSON.stringify(name)}))`);
}

/** Open the standalone UI in picker-less standalone mode with a valid token. */
export async function openManager(page: Page, token: string) {
  await page.goto(`/public/index.html?token=${token}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
}

/** Create a folder through the real modal UI and wait for its card to appear. */
export async function createFolder(scope: Page | FrameLocator, page: Page, name: string) {
  await scope.getByRole('button', { name: 'New folder' }).click();
  await scope.locator('[x-ref="newFolderInput"]').fill(name);
  await scope.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(cardByName(scope, name)).toBeVisible({ timeout: 15_000 });
}

/** Double-click a folder card to navigate into it; waits for the empty-state. */
export async function enterFolder(scope: Page | FrameLocator, name: string) {
  await cardByName(scope, name).dblclick();
  await expect(scope.locator('.ff-empty:has(.ff-empty-cta)')).toBeVisible({ timeout: 10_000 });
}

/** Upload an in-memory file through the hidden file input (bypasses the OS dialog). */
export async function uploadFile(
  scope: Page | FrameLocator,
  file: { name: string; mimeType: string; buffer: Buffer }
) {
  await scope.locator('input[type=file]').first().setInputFiles(file);
}
