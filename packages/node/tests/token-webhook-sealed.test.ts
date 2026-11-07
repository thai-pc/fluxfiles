import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';
import { createToken, decodeToken } from '../src';
import { encryptSecret } from '../src/crypto';

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

  it('matches the committed cross-language vector byte for byte', () => {
    const v = JSON.parse(readFileSync(resolve(process.cwd(), '../../docs/testdata/webhook-secret-vector.json'), 'utf8'));
    expect(encryptSecret(v.plaintext, v.secret, Buffer.from(v.nonce_hex, 'hex'))).toBe(v.blob);
  });
});
