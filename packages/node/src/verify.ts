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
 * Verify an HS256 FluxFiles token: signature + expiry. Returns the decoded
 * claims, or throws on a bad signature, wrong algorithm, or an expired token.
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
  if (typeof claims.exp === 'number' && claims.exp < Math.floor(Date.now() / 1000)) {
    throw new Error('FluxFiles: token has expired');
  }
  return claims;
}
