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

/** Edition preset (DX sugar): default a tier's claims; explicit opts below win. */
const EDITION_PRESETS: Record<string, Record<string, boolean>> = {
  pro: { allow_optimize: true, allow_share: true, allow_intake: true },
  agency: { allow_optimize: true, allow_share: true, allow_intake: true },
  enterprise: { allow_optimize: true, allow_share: true, allow_intake: true, allow_virus_scan: true, allow_c2pa: true },
};

/** Copy the optional per-tenant override claims into a payload when set. */
function applyTenantOverrides(payload: Record<string, unknown>, opts: BaseTokenOptions): void {
  const preset = opts.edition ? EDITION_PRESETS[String(opts.edition).toLowerCase()] : undefined;
  if (preset) for (const [k, v] of Object.entries(preset)) if (!(k in payload)) payload[k] = v;
  if (opts.aiAutoTag !== undefined) payload.ai_auto_tag = !!opts.aiAutoTag;
  if (opts.rateRead && opts.rateRead > 0) payload.rate_read = Math.trunc(opts.rateRead);
  if (opts.rateWrite && opts.rateWrite > 0) payload.rate_write = Math.trunc(opts.rateWrite);
  const variants = sanitizeVariants(opts.variants);
  if (variants) payload.variants = variants;

  // URL-import claims (the server sanitizes/clamps these on decode).
  if (opts.allowUrlImport) payload.allow_url_import = true;
  if (opts.maxImportMb && opts.maxImportMb > 0) payload.max_import_mb = Math.trunc(opts.maxImportMb);
  if (opts.importRateLimit && opts.importRateLimit > 0) payload.import_rate_limit = Math.trunc(opts.importRateLimit);
  if (opts.importConcurrency && opts.importConcurrency > 0) payload.import_concurrency = Math.trunc(opts.importConcurrency);
  if (opts.importPath) payload.import_path = String(opts.importPath);
  if (Array.isArray(opts.importUrlAllowlist) && opts.importUrlAllowlist.length) {
    payload.import_url_allowlist = opts.importUrlAllowlist.map((h) => String(h));
  }

  // Media-preview claims (the server sanitizes/clamps these on decode).
  if (opts.mediaPreview !== undefined) payload.media_preview = !!opts.mediaPreview;
  if (opts.previewUrlTtl && opts.previewUrlTtl > 0) payload.preview_url_ttl = Math.trunc(opts.previewUrlTtl);
  if (opts.maxPreviewMb && opts.maxPreviewMb > 0) payload.max_preview_mb = Math.trunc(opts.maxPreviewMb);
  if (opts.streamTokenTtl && opts.streamTokenTtl > 0) payload.stream_token_ttl = Math.trunc(opts.streamTokenTtl);

  // On-demand WebP claims.
  if (opts.webpEnabled !== undefined) payload.webp_enabled = !!opts.webpEnabled;
  if (opts.webpMaxWidth && opts.webpMaxWidth > 0) payload.webp_max_width = Math.trunc(opts.webpMaxWidth);
  if (opts.webpDefaultQuality && opts.webpDefaultQuality > 0) payload.webp_default_quality = Math.trunc(opts.webpDefaultQuality);
  if (Array.isArray(opts.srcsetWidths)) payload.srcset_widths = opts.srcsetWidths.map((w) => Math.trunc(w));
  if (opts.srcsetSizes) payload.srcset_sizes = String(opts.srcsetSizes);

  // Download gate + watermark.
  if (opts.allowDownload !== undefined) payload.allow_download = !!opts.allowDownload;
  if (opts.allowChmod !== undefined) payload.allow_chmod = !!opts.allowChmod;
  if (opts.allowCodeEdit !== undefined) payload.allow_code_edit = !!opts.allowCodeEdit;
  // SSH terminal (SFTP disks). Core-standalone — the token must target a real core
  // (Node mints for one), not a proxy adapter that doesn't serve /api/fm/terminal.
  if (opts.allowTerminal !== undefined) payload.allow_terminal = !!opts.allowTerminal;
  if (opts.terminalPtyUrl) payload.terminal_pty_url = String(opts.terminalPtyUrl);
  if (opts.allowOptimize !== undefined) payload.allow_optimize = !!opts.allowOptimize;
  // Other paid-module claims (inert unless the module is installed + licensed).
  if (opts.allowShare !== undefined) payload.allow_share = !!opts.allowShare;
  if (opts.allowIntake !== undefined) payload.allow_intake = !!opts.allowIntake;
  if (opts.allowAiVision !== undefined) payload.allow_ai_vision = !!opts.allowAiVision;
  if (opts.allowOcr !== undefined) payload.allow_ocr = !!opts.allowOcr;
  if (opts.allowVirusScan !== undefined) payload.allow_virus_scan = !!opts.allowVirusScan;
  if (opts.allowBackup !== undefined) payload.allow_backup = !!opts.allowBackup;
  if (opts.allowC2pa !== undefined) payload.allow_c2pa = !!opts.allowC2pa;
  if (opts.autoOptimize !== undefined) payload.auto_optimize = !!opts.autoOptimize;
  if (opts.optimizeQuality && opts.optimizeQuality > 0) payload.optimize_quality = Math.trunc(opts.optimizeQuality);
  if (opts.optimizeKeepOriginal !== undefined) payload.optimize_keep_original = !!opts.optimizeKeepOriginal;
  if (opts.optimizeMaxMb && opts.optimizeMaxMb > 0) payload.optimize_max_mb = Math.trunc(opts.optimizeMaxMb);
  if (opts.pdfLevel && ['screen', 'ebook', 'printer', 'prepress', 'default'].includes(opts.pdfLevel)) {
    payload.pdf_level = opts.pdfLevel;
  }
  if (opts.uploadCollision && ['rename', 'overwrite', 'reject'].includes(opts.uploadCollision)) {
    payload.upload_collision = opts.uploadCollision;
  }
  if (opts.showHidden !== undefined) payload.show_hidden = !!opts.showHidden;
  if (opts.dedupeUploads !== undefined) payload.dedupe_uploads = !!opts.dedupeUploads;
  if (opts.allowZip !== undefined) payload.allow_zip = !!opts.allowZip;
  if (opts.allowExtract !== undefined) payload.allow_extract = !!opts.allowExtract;
  if (opts.zipMaxMb && opts.zipMaxMb > 0) payload.zip_max_mb = Math.trunc(opts.zipMaxMb);
  if (opts.zipMaxFiles && opts.zipMaxFiles > 0) payload.zip_max_files = Math.trunc(opts.zipMaxFiles);
  if (opts.watermarkEnabled) {
    payload.watermark_enabled = true;
    if (opts.watermarkType) payload.watermark_type = opts.watermarkType;
    if (opts.watermarkText) payload.watermark_text = String(opts.watermarkText);
    if (opts.watermarkLogoPath) payload.watermark_logo_path = String(opts.watermarkLogoPath);
    if (opts.watermarkPosition) payload.watermark_position = opts.watermarkPosition;
    if (opts.watermarkOpacity !== undefined) payload.watermark_opacity = opts.watermarkOpacity;
    if (opts.watermarkFontSize && opts.watermarkFontSize > 0) payload.watermark_font_size = Math.trunc(opts.watermarkFontSize);
  }

  // Usage-dashboard claims.
  if (opts.usageCacheTtl && opts.usageCacheTtl > 0) payload.usage_cache_ttl = Math.trunc(opts.usageCacheTtl);
  if (opts.usageWarningThreshold && opts.usageWarningThreshold > 0) payload.usage_warning_threshold = Math.trunc(opts.usageWarningThreshold);
  if (opts.usageCriticalThreshold && opts.usageCriticalThreshold > 0) payload.usage_critical_threshold = Math.trunc(opts.usageCriticalThreshold);
  if (opts.usageTopFoldersCount && opts.usageTopFoldersCount > 0) payload.usage_top_folders_count = Math.trunc(opts.usageTopFoldersCount);
  if (opts.usageFolderDepth && opts.usageFolderDepth > 0) payload.usage_folder_depth = Math.trunc(opts.usageFolderDepth);

  // Generic escape hatch: ANY claim by its raw (snake_case) name. Merged last so an
  // explicit claim wins over a preset/group default. The server sanitizes on decode.
  // See docs/CONFIG.md for the full claim list.
  if (opts.claims) for (const [k, v] of Object.entries(opts.claims)) if (v !== undefined && v !== null) payload[k] = v;
}

/**
 * Light client-side validation. The server independently re-validates (incl.
 * SSRF checks on the endpoint), so this only catches obvious mistakes early.
 */
function validateByobDisk(name: string, config: ByobDiskConfig): void {
  if (!config || (config.driver !== 's3' && config.driver !== 'sftp')) {
    throw new Error(`FluxFiles BYOB disk "${name}": driver must be "s3" or "sftp" (the server rejects "local").`);
  }
  if (config.driver === 'sftp') {
    for (const field of ['host', 'username'] as const) {
      if (!config[field]) {
        throw new Error(`FluxFiles BYOB disk "${name}": missing required "${field}".`);
      }
    }
    if (!config.password && !config.private_key) {
      throw new Error(`FluxFiles BYOB disk "${name}": needs a "password" or "private_key".`);
    }
    return;
  }
  for (const field of ['key', 'secret', 'bucket'] as const) {
    if (!config[field]) {
      throw new Error(`FluxFiles BYOB disk "${name}": missing required "${field}".`);
    }
  }
}
