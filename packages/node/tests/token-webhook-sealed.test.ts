import { describe, it, expect } from 'vitest';
import { createToken, decodeToken } from '../src';

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
});
