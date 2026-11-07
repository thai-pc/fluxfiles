import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';
import { createToken, decodeToken } from '../src';
import { decryptSecret, encryptSecret } from '../src/crypto';

const SECRET = 'test-secret-key-that-is-at-least-32-bytes-long';

describe('webhook secret is sealed, never plaintext in the token', () => {
  it('moves webhookSecret / claims.webhook_secret into webhook_secret_enc', () => {
    for (const tok of [
      createToken({ secret: SECRET, userId: 'u', webhookSecret: 'whsec_plain' }),
      createToken({ secret: SECRET, userId: 'u', claims: { webhook_secret: 'whsec_plain' } }),
    ]) {
      const c = decodeToken(tok) as Record<string, unknown>;
      expect(c.webhook_secret).toBeUndefined();
      expect(JSON.stringify(c)).not.toContain('whsec_plain');
      expect(typeof c.webhook_secret_enc).toBe('string');
    }
    const none = decodeToken(createToken({ secret: SECRET, userId: 'u' })) as Record<string, unknown>;
    expect(none.webhook_secret_enc).toBeUndefined();
  });

  it('"0" is a valid key and is sealed; empty string means unset', () => {
    const zero = decodeToken(createToken({ secret: SECRET, userId: 'u', webhookSecret: '0' })) as Record<string, unknown>;
    expect(zero.webhook_secret).toBeUndefined();
    expect(typeof zero.webhook_secret_enc).toBe('string');
    const empty = decodeToken(createToken({ secret: SECRET, userId: 'u', webhookSecret: '' })) as Record<string, unknown>;
    expect(empty.webhook_secret_enc).toBeUndefined();
  });

  it('decrypts the committed cross-language vector and round-trips', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const v = JSON.parse(readFileSync(resolve(here, '../../../docs/testdata/webhook-secret-vector.json'), 'utf8'));
    expect(decryptSecret(v.blob, v.secret)).toBe(v.plaintext);
    expect(decryptSecret(encryptSecret(v.plaintext, v.secret), v.secret)).toBe(v.plaintext);
  });
});
