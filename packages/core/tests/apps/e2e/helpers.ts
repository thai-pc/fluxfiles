import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page, FrameLocator } from '@playwright/test';
import { expect } from '@playwright/test';
import { TEST_SECRET, CORE_ENDPOINT, REACT_PORT, VUE_PORT, LARAVEL_PORT } from './secret';

export type Framework = 'react' | 'vue' | 'laravel';

// e2e/ -> apps -> tests -> core
const coreDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

/** Mint a JWT (read/write/delete on the local disk) signed with TEST_SECRET. */
export function mintToken(perms: string[] = ['read', 'write', 'delete']): string {
  const permsPhp = perms.map((p) => `'${p}'`).join(',');
  const php =
    `require '${coreDir}/embed.php';` +
    `$_ENV['FLUXFILES_SECRET']='${TEST_SECRET}';` +
    `echo fluxfiles_token('adapter-e2e', [${permsPhp}], ['local'], '', 50, null, 86400);`;
  return execFileSync('php', ['-r', php]).toString().trim();
}

/** A 1×1 PNG with a unique salt so duplicate-detection never rejects a repeat. */
export function pngFile(name: string) {
  const b64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
  const salt = Buffer.from(`\n<!-- ${name}:${Math.random()} -->`);
  return { name, mimeType: 'image/png', buffer: Buffer.concat([Buffer.from(b64, 'base64'), salt]) };
}

/**
 * Open a real wrapper host and return a FrameLocator scoped to the embedded core
 * UI, after the host signals READY (the postMessage handshake completed).
 *
 * - react/vue: Vite hosts; we pass a JS-minted token + the core endpoint by query.
 * - laravel: the proxy app at /files mints the token server-side (real adapter
 *   path) and embeds its own origin — no token/endpoint params.
 */
export async function openHost(page: Page, framework: Framework): Promise<FrameLocator> {
  let url: string;
  if (framework === 'laravel') {
    url = `http://127.0.0.1:${LARAVEL_PORT}/files`;
  } else {
    const port = framework === 'react' ? REACT_PORT : VUE_PORT;
    const token = mintToken();
    url = `http://127.0.0.1:${port}/?token=${encodeURIComponent(token)}&endpoint=${encodeURIComponent(CORE_ENDPOINT)}`;
  }
  await page.goto(url);
  // onReady -> READY: the wrapper received FM_READY from the iframe.
  await expect(page.getByTestId('ready-flag')).toHaveText('READY', { timeout: 30_000 });
  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  return fm;
}

/** A `.file-card` inside the iframe whose visible filename is exactly `name`. */
export function cardByName(scope: FrameLocator, name: string) {
  return scope.locator(`.file-card:has(.fname:text-is(${JSON.stringify(name)}))`);
}

/** Upload an in-memory file through the iframe's hidden file input. */
export async function uploadFile(
  scope: FrameLocator,
  file: { name: string; mimeType: string; buffer: Buffer }
) {
  await scope.locator('input[type=file]').first().setInputFiles(file);
}
