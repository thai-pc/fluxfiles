import { describe, it, expect, beforeAll } from 'vitest';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { createToken, createByobToken, decodeToken } from '../src';
import { decryptByob } from '../src/crypto';

// Cross-language parity: tokens minted in Node must decode in the PHP core, and
// BYOB blobs must round-trip both ways (this is the real guard for the HKDF
// salt / AES-GCM / JWT compatibility). Skips cleanly when PHP or the core
// vendor autoloader isn't available (e.g. a JS-only CI lane).
const AUTOLOAD = resolve(process.cwd(), '../core/vendor/autoload.php');
const SECRET = 'php-compat-secret-key-at-least-32-bytes!!';

function phpAvailable(): boolean {
  try {
    execFileSync('php', ['-v'], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

const ENABLED = phpAvailable() && existsSync(AUTOLOAD);

/** Run a tiny PHP script with the core autoloader; data passed via env. */
function php(code: string, env: Record<string, string>): string {
  return execFileSync('php', ['-r', `require getenv('FF_AUTOLOAD');\n${code}`], {
    encoding: 'utf8',
    env: { ...process.env, FF_AUTOLOAD: AUTOLOAD, FF_SECRET: SECRET, ...env },
  }).trim();
}

describe.skipIf(!ENABLED)('PHP ↔ Node compatibility', () => {
  beforeAll(() => {
    // Surface a helpful note when the suite is skipped for missing deps.
    if (!ENABLED) console.warn('php-compat: skipped (php or core vendor autoload missing)');
  });

  it('a Node-minted token decodes natively in the PHP core', () => {
    const token = createToken({
      secret: SECRET,
      userId: 'php-user',
      perms: ['read', 'write', 'delete'],
      disks: ['local', 's3'],
      prefix: 'team/9',
      maxUploadMb: 42,
      allowedExt: ['png', 'webp'],
      maxStorageMb: 200,
      maxFiles: 7,
      ownerOnly: true,
    });
    const out = php(`echo json_encode(\\FluxFiles\\JwtCompat::decode(getenv('FF_TOKEN'), getenv('FF_SECRET')));`, {
      FF_TOKEN: token,
    });
    const c = JSON.parse(out);
    expect(c.sub).toBe('php-user');
    expect(c.perms).toEqual(['read', 'write', 'delete']);
    expect(c.disks).toEqual(['local', 's3']);
    expect(c.prefix).toBe('team/9');
    expect(c.max_upload).toBe(42);
    expect(c.allowed_ext).toEqual(['png', 'webp']);
    expect(c.max_storage).toBe(200);
    expect(c.max_files).toBe(7);
    expect(c.owner_only).toBe(true);
  });

  it('a Node-encrypted BYOB blob decrypts in PHP CredentialEncryptor', () => {
    const cfg = { driver: 's3', key: 'AKIA-NODE', secret: 'node-secret', bucket: 'node-bucket', region: 'us-east-2' };
    const token = createByobToken({ secret: SECRET, userId: 'u', byobDisks: { 'my-s3': cfg } });
    const blob = decodeToken(token).byob_disks!['my-s3'];
    const out = php(`echo json_encode(\\FluxFiles\\CredentialEncryptor::decrypt(getenv('FF_BLOB'), getenv('FF_SECRET')));`, {
      FF_BLOB: blob,
    });
    expect(JSON.parse(out)).toEqual(cfg);
  });

  it('a PHP-encrypted BYOB blob decrypts in Node (HKDF salt parity, both directions)', () => {
    const cfg = { driver: 's3', key: 'AKIA-PHP', secret: 'php-secret', bucket: 'php-bucket', region: 'ap-south-1' };
    const blob = php(
      `echo \\FluxFiles\\CredentialEncryptor::encrypt(json_decode(getenv('FF_CONFIG'), true), getenv('FF_SECRET'));`,
      { FF_CONFIG: JSON.stringify(cfg) },
    );
    expect(decryptByob(blob, SECRET)).toEqual(cfg);
  });
});
