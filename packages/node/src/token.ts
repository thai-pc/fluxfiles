import { randomBytes } from 'node:crypto';
import { base64url, hmacSha256, encryptByob } from './crypto';
import type { BaseTokenOptions, ByobDiskConfig, CreateByobTokenOptions, CreateTokenOptions } from './types';

const MIN_SECRET_BYTES = 32;

function resolveSecret(explicit?: string): string {
  const secret = explicit ?? process.env.FLUXFILES_SECRET ?? '';
  if (Buffer.byteLength(secret, 'utf8') < MIN_SECRET_BYTES) {
    throw new Error(
      `FluxFiles: signing secret must be at least ${MIN_SECRET_BYTES} bytes ` +
        '(HS256 key requirement). Set `secret` or FLUXFILES_SECRET.',
    );
  }
  return secret;
}

/** Sign a payload as a compact HS256 JWT. */
function sign(payload: Record<string, unknown>, secret: string): string {
  const header = base64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
  const body = base64url(JSON.stringify(payload));
  const sig = base64url(hmacSha256(`${header}.${body}`, secret));
  return `${header}.${body}.${sig}`;
}

function newJti(): string {
  return randomBytes(12).toString('hex');
}

/**
 * Mint a standard FluxFiles JWT. The payload mirrors the PHP `fluxfiles_token()`
 * helper exactly, so the FluxFiles core decodes it natively.
 */
export function createToken(opts: CreateTokenOptions): string {
  const secret = resolveSecret(opts.secret);
  const now = Math.floor(Date.now() / 1000);
  const payload: Record<string, unknown> = {
    sub: opts.userId,
    iat: now,
    exp: now + (opts.ttl ?? 3600),
    jti: newJti(),
    perms: opts.perms ?? ['read'],
    disks: opts.disks ?? ['local'],
    prefix: opts.prefix ?? '',
    max_upload: opts.maxUploadMb ?? 10,
    allowed_ext: opts.allowedExt ?? null,
    max_storage: opts.maxStorageMb ?? 0,
    max_files: opts.maxFiles ?? 0,
  };
  if (opts.ownerOnly) payload.owner_only = true;
  applyTenantOverrides(payload, opts);
  return sign(payload, secret);
}

/**
 * Mint a BYOB token. Each disk's S3-compatible credentials are AES-256-GCM
 * encrypted into the token (decrypted only at runtime by the server). Mirrors
 * the PHP `fluxfiles_byob_token()` helper.
 */
export function createByobToken(opts: CreateByobTokenOptions): string {
  const secret = resolveSecret(opts.secret);
  const now = Math.floor(Date.now() / 1000);

  const encrypted: Record<string, string> = {};
  const names: string[] = [];
  for (const [name, config] of Object.entries(opts.byobDisks)) {
    validateByobDisk(name, config);
    encrypted[name] = encryptByob(config, secret);
    names.push(name);
  }

  const payload: Record<string, unknown> = {
    sub: opts.userId,
    iat: now,
    exp: now + (opts.ttl ?? 1800),
    jti: newJti(),
    perms: opts.perms ?? ['read', 'write'],
    disks: names,
    prefix: opts.prefix ?? '',
    max_upload: opts.maxUploadMb ?? 10,
    allowed_ext: opts.allowedExt ?? null,
    byob_disks: encrypted,
  };
  if (opts.ownerOnly) payload.owner_only = true;
  applyTenantOverrides(payload, opts);
  return sign(payload, secret);
}

/** Sanitize the per-tenant `variants` claim — matches PHP `Claims::sanitizeVariants`. */
function sanitizeVariants(v: BaseTokenOptions['variants']): Record<string, number> | null {
  if (!v || typeof v !== 'object') return null;
  const out: Record<string, number> = {};
  for (const name of ['thumb', 'medium', 'large'] as const) {
    const w = Math.trunc(Number((v as Record<string, unknown>)[name]));
    if (Number.isFinite(w) && w >= 16 && w <= 8000) out[name] = w;
  }
  return Object.keys(out).length ? out : null;
}

/** Copy the optional per-tenant override claims into a payload when set. */
function applyTenantOverrides(payload: Record<string, unknown>, opts: BaseTokenOptions): void {
  if (opts.aiAutoTag !== undefined) payload.ai_auto_tag = !!opts.aiAutoTag;
  if (opts.rateRead && opts.rateRead > 0) payload.rate_read = Math.trunc(opts.rateRead);
  if (opts.rateWrite && opts.rateWrite > 0) payload.rate_write = Math.trunc(opts.rateWrite);
  const variants = sanitizeVariants(opts.variants);
  if (variants) payload.variants = variants;
}

/**
 * Light client-side validation. The server independently re-validates (incl.
 * SSRF checks on the endpoint), so this only catches obvious mistakes early.
 */
function validateByobDisk(name: string, config: ByobDiskConfig): void {
  if (!config || config.driver !== 's3') {
    throw new Error(`FluxFiles BYOB disk "${name}": driver must be "s3" (the server rejects "local").`);
  }
  for (const field of ['key', 'secret', 'bucket'] as const) {
    if (!config[field]) {
      throw new Error(`FluxFiles BYOB disk "${name}": missing required "${field}".`);
    }
  }
}
