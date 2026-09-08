import { describe, it, expect, beforeAll } from 'vitest';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createToken, createByobToken, decodeToken } from '../src';
import { decryptByob } from '../src/crypto';

// Cross-language parity: tokens minted in Node must decode in the PHP core, and
// BYOB blobs must round-trip both ways (this is the real guard for the HKDF
// salt / AES-GCM / JWT compatibility). Skips cleanly when PHP or the core
// vendor autoloader isn't available (e.g. a JS-only CI lane).
const AUTOLOAD = resolve(process.cwd(), '../core/vendor/autoload.php');
const SECRET = 'php-compat-secret-key-at-least-32-bytes!!';

// Shared cross-language fixtures (docs/testdata/, docs/PYTHON-TOKEN-SDK-DESIGN.md
// §6.1) — the same vectors PHP's test-role-preset.php/test-byob.php and this
// package's token.test.ts load, so the exact input/expected-claim pair every
// language's own suite already checks is also proven to decode in *live* PHP here.
const TOKEN_VECTORS = JSON.parse(
  readFileSync(resolve(process.cwd(), '../../docs/testdata/token-vectors.json'), 'utf8'),
) as { plain_tokens: Array<{ name: string; input: Record<string, unknown>; expect: Record<string, unknown> }> };
const BYOB_VECTORS = JSON.parse(
  readFileSync(resolve(process.cwd(), '../../docs/testdata/byob-vectors.json'), 'utf8'),
) as { decrypt_vectors: Array<{ name: string; expected_config: Record<string, unknown> }> };
const S3_VECTOR_CONFIG = BYOB_VECTORS.decrypt_vectors.find((v) => v.name === 's3_with_endpoint')!.expected_config;

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
    const vector = TOKEN_VECTORS.plain_tokens.find((v) => v.name === 'exact_claim_shape')!;
    const input = vector.input as { user_id: string; ttl_seconds: number; claims: Record<string, unknown> };
    const token = createToken({ secret: SECRET, userId: input.user_id, ttl: input.ttl_seconds, claims: input.claims });
    const out = php(`echo json_encode(\\FluxFiles\\JwtCompat::decode(getenv('FF_TOKEN'), getenv('FF_SECRET')));`, {
      FF_TOKEN: token,
    });
    const c = JSON.parse(out);
    for (const [key, expected] of Object.entries(vector.expect)) {
      if (key === 'ttl_seconds') {
        expect(c.exp - c.iat).toBe(expected);
        continue;
      }
      expect(c[key]).toEqual(expected);
    }
  });

  it('a Node-encrypted BYOB blob decrypts in PHP CredentialEncryptor', () => {
    const token = createByobToken({ secret: SECRET, userId: 'u', byobDisks: { 'my-s3': S3_VECTOR_CONFIG as never } });
    const blob = decodeToken(token).byob_disks!['my-s3'];
    const out = php(`echo json_encode(\\FluxFiles\\CredentialEncryptor::decrypt(getenv('FF_BLOB'), getenv('FF_SECRET')));`, {
      FF_BLOB: blob,
    });
    expect(JSON.parse(out)).toEqual(S3_VECTOR_CONFIG);
  });

  it('a PHP-encrypted BYOB blob decrypts in Node (HKDF salt parity, both directions)', () => {
    const blob = php(
      `echo \\FluxFiles\\CredentialEncryptor::encrypt(json_decode(getenv('FF_CONFIG'), true), getenv('FF_SECRET'));`,
      { FF_CONFIG: JSON.stringify(S3_VECTOR_CONFIG) },
    );
    expect(decryptByob(blob, SECRET)).toEqual(S3_VECTOR_CONFIG);
  });
});
