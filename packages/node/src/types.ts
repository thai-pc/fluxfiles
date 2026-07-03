/** A FluxFiles permission. `audit` gates reading the activity log. */
export type FluxPermission = 'read' | 'write' | 'delete' | 'audit';

/**
 * A BYOB (Bring Your Own Bucket) disk config. Encrypted into the JWT and
 * decrypted only at runtime by the FluxFiles server. Only S3-compatible
 * storage is allowed — the server rejects the `local` driver.
 */
export interface ByobS3DiskConfig {
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

/**
 * A BYOB SFTP disk — a user's own SFTP server (e.g. a VPS). Auth is a password OR
 * a private key. The server SSRF-checks the host (no loopback/private/metadata
 * targets). SFTP files are streamed through the app (no static/presigned URL).
 */
export interface ByobSftpDiskConfig {
  driver: 'sftp';
  host: string;
  username: string;
  password?: string;
  private_key?: string;
  private_key_passphrase?: string;
  port?: number;
  root?: string;
}

export type ByobDiskConfig = ByobS3DiskConfig | ByobSftpDiskConfig;

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
  /** Responsive `srcset` width ladder (px); list() emits these as `img_srcset` on images.
   *  Snapped to 100px and clamped to webpMaxWidth on decode. Omit to inherit the default
   *  `[320, 640, 768, 1024, 1366, 1920]`. */
  srcsetWidths?: number[];
  /** Optional `sizes` attribute surfaced as `img_sizes` (omit to let the host supply its own). */
  srcsetSizes?: string;
  /** May this token mint clean original download URLs? Default true. `false` = preview-only
   *  (list withholds url/permanent_url/variants; GET presign is denied — only watermarked img_base). */
  allowDownload?: boolean;
  /** Allow chmod (POST /api/fm/chmod) on an SFTP disk. Default true; `false` = read-only permissions. */
  allowChmod?: boolean;
  /** Allow editing a file's text content (GET/PUT /api/fm/content). Default **false** — editing
   *  config/executable files (wp-config.php, .env, nginx.conf, deploy.sh) is powerful, so it's opt-in. */
  allowCodeEdit?: boolean;
  /** Allow the SSH terminal (POST /api/fm/terminal) on an SFTP disk. Default **false** — grants shell
   *  access as the SSH user, so it's opt-in. Core-standalone: the token must target a real core
   *  (this SDK does), not a proxy adapter that doesn't serve /api/fm/terminal. */
  allowTerminal?: boolean;
  /** Optional self-hosted PTY terminal URL (ttyd / gotty / wetty, or any PTY-over-WebSocket
   *  server the operator runs). When set (and `allowTerminal` is true), the UI embeds it for a
   *  true interactive terminal instead of the built-in command-runner. Free; must be http(s).
   *  Core-standalone only (like `allowTerminal`). */
  terminalPtyUrl?: string;
  /** Allow the Optimization feature (POST /api/fm/optimize) — recompress images to WebP +
   *  compress PDFs. **Free/core**; default **false** because it replaces/deletes originals, so
   *  it's an opt-in capability. */
  allowOptimize?: boolean;
  /** Auto-optimize images on upload (recompress to WebP in the pipeline). Default false.
   *  Free/core. */
  autoOptimize?: boolean;
  /** WebP quality for optimization, 40–95. `0`/omitted = inherit the server default (82). */
  optimizeQuality?: number;
  /** Keep the original file when optimizing (default false = replace in place). */
  optimizeKeepOriginal?: boolean;
  /** Skip optimizing a file larger than this many MB (0/omitted = no limit). */
  optimizeMaxMb?: number;
  /** Ghostscript preset for PDF optimization. Default `'ebook'`. */
  pdfLevel?: 'screen' | 'ebook' | 'printer' | 'prepress' | 'default';
  /** How an upload whose NAME collides with an existing different file is handled
   *  (content dedup is separate, by hash): `'rename'` keep both `<name>-1.<ext>`
   *  (default), `'overwrite'` replace in place, `'reject'` 409 for the host to prompt. */
  uploadCollision?: 'rename' | 'overwrite' | 'reject';
  /** Show dotfiles (.env, .gitignore, …) in listings + search. Default **false** —
   *  dotfiles are hidden, matching Finder / cPanel / Nextcloud. */
  showHidden?: boolean;
  /** Refuse byte-for-byte duplicate uploads (SHA-256). Default **false** — an
   *  identical re-upload is kept as a copy (like Finder / Drive / Dropbox); set true
   *  to save storage by blocking duplicates. */
  dedupeUploads?: boolean;
  /** Edition preset that defaults a tier's claims (`pro`/`agency`/`enterprise`).
   *  DX sugar — explicit claim options still win, and the license gates the code. */
  edition?: 'free' | 'pro' | 'agency' | 'enterprise';
  /** Paid-module claims (3-layer gate: code installed + licensed + this claim).
   *  All default off; inert unless the module is installed & licensed on the server. */
  allowShare?: boolean;
  allowIntake?: boolean;
  allowVersioning?: boolean;
  allowAiVision?: boolean;
  allowOcr?: boolean;
  allowVirusScan?: boolean;
  allowBackup?: boolean;
  allowC2pa?: boolean;
  /** Prior versions kept per file (Versioning module). `0`/omitted = default (10, cap 100). */
  versioningMax?: number;
  /** Skip versioning files bigger than this many MB. `0`/omitted = default (25). */
  versioningMaxMb?: number;
  /** Allow downloading a multi-file/-folder selection as a zip (POST /api/fm/zip). Default true. */
  allowZip?: boolean;
  /** Allow extracting a zip in place (POST /api/fm/extract). Default true. */
  allowExtract?: boolean;
  /** Max total uncompressed size (MB) for a zip/extract. `0`/omitted = inherit (1024). */
  zipMaxMb?: number;
  /** Max file count for a zip/extract. `0`/omitted = inherit (10000). */
  zipMaxFiles?: number;
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
  /** Usage-summary cache TTL (seconds). `0`/omitted = inherit (900). */
  usageCacheTtl?: number;
  /** Percent at which quota status becomes "warning". `0`/omitted = inherit (70). */
  usageWarningThreshold?: number;
  /** Percent at which quota status becomes "critical". `0`/omitted = inherit (90). */
  usageCriticalThreshold?: number;
  /** Number of largest folders the usage dashboard returns. `0`/omitted = inherit (10). */
  usageTopFoldersCount?: number;
  /** Folder grouping depth for the usage breakdown. `0`/omitted = inherit (1). */
  usageFolderDepth?: number;
  /** Generic escape hatch: any JWT claim by its raw snake_case name (e.g.
   *  `{ allow_terminal: true, terminal_pty_url: '…', upload_collision: 'overwrite' }`).
   *  Merged last so explicit claims win; the server sanitizes on decode. The single
   *  place to set claims that don't have a typed option here. See docs/CONFIG.md. */
  claims?: Record<string, unknown>;
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
