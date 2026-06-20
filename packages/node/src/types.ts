/** A FluxFiles permission. `audit` gates reading the activity log. */
export type FluxPermission = 'read' | 'write' | 'delete' | 'audit';

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
  /** Per-tenant AI auto-tag toggle. Omit to inherit the server default. */
  aiAutoTag?: boolean;
  /** Per-tenant read rate limit (requests/min). `0`/omitted = inherit server default. */
  rateRead?: number;
  /** Per-tenant write rate limit (requests/min). `0`/omitted = inherit server default. */
  rateWrite?: number;
  /** Per-tenant image variant widths, e.g. `{ thumb: 150, medium: 768, large: 1920 }`. Omit to inherit. */
  variants?: Partial<Record<'thumb' | 'medium' | 'large', number>> | null;
  /** Enable Import-from-URL for this tenant (`POST /api/fm/import-url`). Default off. */
  allowUrlImport?: boolean;
  /** Max size per URL import, in MB (same unit as `maxUploadMb`). `0`/omitted = inherit (50). */
  maxImportMb?: number;
  /** Restrict imports to these host globs, e.g. `['*.unsplash.com']`. Omit = any public host. */
  importUrlAllowlist?: string[];
  /** Force imports into this path, ignoring the request path. */
  importPath?: string;
  /** Import-specific rate limit (req/min) and max concurrent imports. `0`/omitted = inherit. */
  importRateLimit?: number;
  importConcurrency?: number;
  /** Enable inline video/audio preview for this tenant. Omit to inherit the default (true). */
  mediaPreview?: boolean;
  /** Presigned media-URL TTL (seconds) — longer so a long video doesn't expire mid-play. `0`/omitted = inherit (7200). */
  previewUrlTtl?: number;
  /** Max file size (MB) eligible for inline preview; larger media shows a download placeholder. `0`/omitted = inherit (500). */
  maxPreviewMb?: number;
  /** TTL (seconds) for per-file gated-local stream tokens. `0`/omitted = inherit (3600). */
  streamTokenTtl?: number;
  /** Expose the on-demand WebP endpoint (`/api/fm/img`) for images. Omit to inherit the default (true). */
  webpEnabled?: boolean;
  /** Max resize width (px) a transform request may ask for. `0`/omitted = inherit (2000). */
  webpMaxWidth?: number;
  /** Default WebP quality when a request omits it. `0`/omitted = inherit (80). */
  webpDefaultQuality?: number;
  /** May this token mint clean original download URLs? Default true. `false` = preview-only
   *  (list withholds url/permanent_url/variants; GET presign is denied — only watermarked img_base). */
  allowDownload?: boolean;
  /** Enable on-the-fly watermark on `/api/fm/img` (source file is never modified). Default off. */
  watermarkEnabled?: boolean;
  /** Watermark kind: 'text' or 'logo' (a PNG path in storage). Default 'text'. */
  watermarkType?: 'text' | 'logo';
  /** Watermark text (for type 'text'). */
  watermarkText?: string;
  /** Storage path to the logo PNG (for type 'logo'); missing → text fallback. */
  watermarkLogoPath?: string;
  /** Position: top-left / top-right / bottom-left / bottom-right / center. Default bottom-right. */
  watermarkPosition?: 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right' | 'center';
  /** Opacity 0.0–1.0. Default 0.6. */
  watermarkOpacity?: number;
  /** Font size for text watermark. Default 24. */
  watermarkFontSize?: number;
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
