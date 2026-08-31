import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateSync } from 'node:zlib';
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

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  return table;
})();

function crc32(buf: Buffer): number {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function pngChunk(type: string, data: Buffer): Buffer {
  const typeBuf = Buffer.from(type, 'ascii');
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])));
  return Buffer.concat([len, typeBuf, data, crc]);
}

/**
 * A real (not 1x1) solid-color PNG, hand-assembled with only Node's built-in
 * zlib — needed because buildSrcset() in FileManager.php intentionally
 * returns '' for images narrower than 100px ("too small to bother with
 * responsive variants"), which the tiny pngFile() above always triggers.
 */
export function largePngFile(name: string, width = 200, height = 150) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdrData = Buffer.alloc(13);
  ihdrData.writeUInt32BE(width, 0);
  ihdrData.writeUInt32BE(height, 4);
  ihdrData.writeUInt8(8, 8); // bit depth
  ihdrData.writeUInt8(2, 9); // color type: RGB
  ihdrData.writeUInt8(0, 10);
  ihdrData.writeUInt8(0, 11);
  ihdrData.writeUInt8(0, 12);

  const raw = Buffer.alloc(height * (1 + width * 3));
  let salt = Math.floor(Math.random() * 255);
  for (let y = 0; y < height; y++) {
    const rowStart = y * (1 + width * 3);
    raw[rowStart] = 0; // filter: none
    for (let x = 0; x < width; x++) {
      const p = rowStart + 1 + x * 3;
      raw[p] = salt;
      raw[p + 1] = 100;
      raw[p + 2] = 200;
    }
  }
  const idatData = deflateSync(raw);

  const png = Buffer.concat([
    sig,
    pngChunk('IHDR', ihdrData),
    pngChunk('IDAT', idatData),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
  return { name, mimeType: 'image/png', buffer: png };
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
