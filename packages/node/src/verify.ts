import { base64url, hmacSha256, safeEqual } from './crypto';
import type { FluxClaims } from './types';

function decodeSegment<T>(seg: string): T {
  return JSON.parse(Buffer.from(seg, 'base64url').toString('utf8')) as T;
}

/**
 * Decode a token WITHOUT verifying the signature. Useful for inspection/logging;
 * never trust the result for authorization — use {@link verifyToken}.
 */
export function decodeToken(token: string): FluxClaims {
  const parts = token.split('.');
  if (parts.length !== 3) throw new Error('FluxFiles: malformed token');
  return decodeSegment<FluxClaims>(parts[1]);
}

/**
 * Verify an HS256 FluxFiles **API access token**: signature, a numeric `exp`
 * that has not passed, and no type marker. Same rules as core's JwtMiddleware:
 * a token without `exp` never expires, and the narrow tokens signed with the
 * same secret (`t` = img/stream/share/intake/…, or the legacy `share`/`intake`
 * flags) are only safe on their own endpoints, so neither is accepted here.
 * Returns the decoded claims, or throws.
 */
export function verifyToken(token: string, secret?: string): FluxClaims {
  const key = secret ?? process.env.FLUXFILES_SECRET ?? '';
  if (!key) throw new Error('FluxFiles: no secret provided to verifyToken');

  const parts = token.split('.');
  if (parts.length !== 3) throw new Error('FluxFiles: malformed token');
  const [header, body, sig] = parts;

  const alg = decodeSegment<{ alg?: string }>(header).alg;
  if (alg !== 'HS256') throw new Error(`FluxFiles: unexpected JWT alg "${alg}"`);

  const expected = base64url(hmacSha256(`${header}.${body}`, key));
  if (!safeEqual(sig, expected)) throw new Error('FluxFiles: invalid token signature');

  const claims = decodeSegment<FluxClaims>(body);
  if (!claims || typeof claims !== 'object' || Array.isArray(claims)) {
    throw new Error('FluxFiles: malformed token claims');
  }
  const now = Math.floor(Date.now() / 1000);
  for (const name of ['exp', 'nbf', 'iat'] as const) {
    if (claims[name] !== undefined && (typeof claims[name] !== 'number' || !Number.isFinite(claims[name]))) {
      throw new Error(`FluxFiles: invalid ${name} claim`);
    }
  }
  if (typeof claims.exp !== 'number') {
    throw new Error('FluxFiles: token has no exp claim');
  }
  const c = claims as unknown as Record<string, unknown>;
  if (c.t !== undefined || Boolean(c.share) || Boolean(c.intake)) {
    throw new Error('FluxFiles: not an API access token');
  }
  if (claims.exp <= now) {
    throw new Error('FluxFiles: token has expired');
  }
  if ((typeof claims.nbf === 'number' && claims.nbf > now)
    || (claims.nbf === undefined && typeof claims.iat === 'number' && claims.iat > now)) {
    throw new Error('FluxFiles: token is not yet valid');
  }
  return claims;
}
