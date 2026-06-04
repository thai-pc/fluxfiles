/** A FluxFiles permission. */
export type FluxPermission = 'read' | 'write' | 'delete';

/**
 * A BYOB (Bring Your Own Bucket) disk config. Encrypted into the JWT and
 * decrypted only at runtime by the FluxFiles server. Only S3-compatible
 * storage is allowed — the server rejects the `local` driver.
 */
export interface ByobDiskConfig {
  driver: 's3';
  key: string;
  secret: string;
  bucket: string;
  region?: string;
  /** Custom S3 endpoint (R2, MinIO, Spaces, …). Omit for native AWS S3. */
  endpoint?: string;
  visibility?: 'private' | 'public';
  /** Public base URL for direct (unsigned) object links on a public disk. */
  public_url?: string;
}

/** Options shared by all token builders. */
export interface BaseTokenOptions {
  /** HS256 signing secret. Defaults to `process.env.FLUXFILES_SECRET`. Must be ≥ 32 bytes. */
  secret?: string;
  /** Subject — your application's user id. */
  userId: string;
  perms?: FluxPermission[];
  /** Path prefix the user is scoped to (e.g. `users/42`). */
  prefix?: string;
  maxUploadMb?: number;
  /** Allowed extensions (lowercase, no dot). `null`/omitted = all non-dangerous types. */
  allowedExt?: string[] | null;
  /** Time-to-live in seconds. */
  ttl?: number;
  /** Restrict destructive ops to files the user uploaded. */
  ownerOnly?: boolean;
}

export interface CreateTokenOptions extends BaseTokenOptions {
  /** Disk names the token may access. */
  disks?: string[];
  maxStorageMb?: number;
  /** Total file count cap under the prefix. `0` = unlimited. */
  maxFiles?: number;
}

export interface CreateByobTokenOptions extends BaseTokenOptions {
  /** Map of disk name → S3-compatible credentials, encrypted into the token. */
  byobDisks: Record<string, ByobDiskConfig>;
}

/** Decoded JWT payload (snake_case, as emitted by the PHP core). */
export interface FluxClaims {
  sub: string;
  iat: number;
  exp: number;
  jti: string;
  perms: string[];
  disks: string[];
  prefix: string;
  max_upload: number;
  allowed_ext: string[] | null;
  max_storage?: number;
  max_files?: number;
  owner_only?: boolean;
  byob_disks?: Record<string, string>;
}
