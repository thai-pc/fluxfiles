import {
  createHmac,
  hkdfSync,
  randomBytes,
  createCipheriv,
  createDecipheriv,
  timingSafeEqual,
} from 'node:crypto';

/** base64url-encode without padding (JWT segment encoding). */
export function base64url(input: Buffer | string): string {
  return Buffer.from(input).toString('base64url');
}

export function hmacSha256(data: string, secret: string): Buffer {
  return createHmac('sha256', secret).update(data).digest();
}

/** Constant-time comparison of two base64url signatures. */
export function safeEqual(a: string, b: string): boolean {
  const ba = Buffer.from(a);
  const bb = Buffer.from(b);
  return ba.length === bb.length && timingSafeEqual(ba, bb);
}

// ── BYOB credential encryption (matches PHP CredentialEncryptor) ──────────────
// AES-256-GCM. Key = HKDF-SHA256(ikm = secret, salt = 32 zero bytes,
// info = "fluxfiles-byob-enc", len = 32). Blob = base64(nonce[12] | ct | tag[16]).
const BYOB_INFO = 'fluxfiles-byob-enc';
const NONCE_LEN = 12;
const TAG_LEN = 16;

/**
 * Derive the BYOB key exactly like PHP `hash_hkdf('sha256', $secret, 32, $info)`.
 * PHP's empty HKDF salt means "HashLen zero bytes" (RFC 5869), so we pass an
 * explicit 32-byte zero salt — passing an empty Buffer to Node's hkdfSync would
 * NOT match.
 */
function deriveByobKey(secret: string): Buffer {
  const salt = Buffer.alloc(32, 0);
  const dk = hkdfSync('sha256', Buffer.from(secret, 'utf8'), salt, Buffer.from(BYOB_INFO, 'utf8'), 32);
  return Buffer.from(dk);
}

export function encryptByob(config: unknown, secret: string): string {
  const key = deriveByobKey(secret);
  const nonce = randomBytes(NONCE_LEN);
  const cipher = createCipheriv('aes-256-gcm', key, nonce, { authTagLength: TAG_LEN });
  // JSON.stringify does not escape "/", matching PHP's JSON_UNESCAPED_SLASHES.
  const plaintext = JSON.stringify(config);
  const ct = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return Buffer.concat([nonce, ct, tag]).toString('base64');
}

export function decryptByob<T = unknown>(blob: string, secret: string): T {
  const key = deriveByobKey(secret);
  const raw = Buffer.from(blob, 'base64');
  if (raw.length < NONCE_LEN + TAG_LEN + 1) {
    throw new Error('FluxFiles: invalid BYOB credential blob');
  }
  const nonce = raw.subarray(0, NONCE_LEN);
  const tag = raw.subarray(raw.length - TAG_LEN);
  const ct = raw.subarray(NONCE_LEN, raw.length - TAG_LEN);
  const decipher = createDecipheriv('aes-256-gcm', key, nonce, { authTagLength: TAG_LEN });
  decipher.setAuthTag(tag);
  const pt = Buffer.concat([decipher.update(ct), decipher.final()]).toString('utf8');
  return JSON.parse(pt) as T;
}
