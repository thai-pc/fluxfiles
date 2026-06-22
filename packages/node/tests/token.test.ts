import { describe, it, expect } from 'vitest';
import { createToken, createByobToken, verifyToken, decodeToken } from '../src';
import { encryptByob, decryptByob } from '../src/crypto';

const SECRET = 'test-secret-key-that-is-at-least-32-bytes-long';

describe('createToken', () => {
  it('emits the exact PHP claim shape', () => {
    const token = createToken({
      secret: SECRET,
      userId: 'user-1',
      perms: ['read', 'write'],
      disks: ['local', 's3'],
      prefix: 'users/1',
      maxUploadMb: 25,
      allowedExt: ['png', 'jpg'],
      ttl: 600,
      maxStorageMb: 100,
      maxFiles: 50,
    });
    const c = decodeToken(token);
    expect(c.sub).toBe('user-1');
    expect(c.perms).toEqual(['read', 'write']);
    expect(c.disks).toEqual(['local', 's3']);
    expect(c.prefix).toBe('users/1');
    expect(c.max_upload).toBe(25);
    expect(c.allowed_ext).toEqual(['png', 'jpg']);
    expect(c.max_storage).toBe(100);
    expect(c.max_files).toBe(50);
    expect(c.exp - c.iat).toBe(600);
    expect(c.jti).toMatch(/^[0-9a-f]{24}$/);
  });

  it('defaults allowed_ext to null and omits owner_only when false', () => {
    const c = decodeToken(createToken({ secret: SECRET, userId: 'u' }));
    expect(c.allowed_ext).toBeNull();
    expect(c.owner_only).toBeUndefined();
    expect(c.perms).toEqual(['read']);
    expect(c.disks).toEqual(['local']);
  });

  it('sets owner_only only when requested', () => {
    const c = decodeToken(createToken({ secret: SECRET, userId: 'u', ownerOnly: true }));
    expect(c.owner_only).toBe(true);
  });

  it('emits the per-tenant overrides and sanitizes variants (PHP parity)', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        aiAutoTag: true,
        rateRead: 120,
        rateWrite: 30,
        variants: { thumb: 64, medium: 1024, large: 99999 }, // 99999 out of range → dropped
      }),
    ) as Record<string, unknown>;
    expect(c.ai_auto_tag).toBe(true);
    expect(c.rate_read).toBe(120);
    expect(c.rate_write).toBe(30);
    expect(c.variants).toEqual({ thumb: 64, medium: 1024 });
  });

  it('omits per-tenant overrides when unset (lean token)', () => {
    const c = decodeToken(createToken({ secret: SECRET, userId: 'u' })) as Record<string, unknown>;
    expect(c.ai_auto_tag).toBeUndefined();
    expect(c.rate_read).toBeUndefined();
    expect(c.rate_write).toBeUndefined();
    expect(c.variants).toBeUndefined();
    expect(c.allow_url_import).toBeUndefined();
    expect(c.max_import_mb).toBeUndefined();
  });

  it('forwards media-preview claims (PHP parity)', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        mediaPreview: false,
        previewUrlTtl: 7200,
        maxPreviewMb: 250,
        streamTokenTtl: 1800,
      }),
    ) as Record<string, unknown>;
    expect(c.media_preview).toBe(false);
    expect(c.preview_url_ttl).toBe(7200);
    expect(c.max_preview_mb).toBe(250);
    expect(c.stream_token_ttl).toBe(1800);
  });

  it('forwards usage-dashboard claims (PHP parity)', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        usageCacheTtl: 600,
        usageWarningThreshold: 60,
        usageCriticalThreshold: 85,
        usageTopFoldersCount: 5,
        usageFolderDepth: 2,
      }),
    ) as Record<string, unknown>;
    expect(c.usage_cache_ttl).toBe(600);
    expect(c.usage_warning_threshold).toBe(60);
    expect(c.usage_critical_threshold).toBe(85);
    expect(c.usage_top_folders_count).toBe(5);
    expect(c.usage_folder_depth).toBe(2);
  });

  it('forwards watermark + allow_download claims (PHP parity)', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        allowDownload: false,
        allowChmod: false,
        allowCodeEdit: true,
        allowOptimize: true,
        allowZip: false,
        allowExtract: false,
        zipMaxMb: 50,
        zipMaxFiles: 7,
        watermarkEnabled: true,
        watermarkType: 'text',
        watermarkText: '© Acme',
        watermarkPosition: 'center',
        watermarkOpacity: 0.5,
        watermarkFontSize: 20,
      }),
    ) as Record<string, unknown>;
    expect(c.allow_download).toBe(false);
    expect(c.allow_chmod).toBe(false);
    expect(c.allow_code_edit).toBe(true);
    expect(c.allow_optimize).toBe(true);
    expect(c.allow_zip).toBe(false);
    expect(c.allow_extract).toBe(false);
    expect(c.zip_max_mb).toBe(50);
    expect(c.zip_max_files).toBe(7);
    expect(c.watermark_enabled).toBe(true);
    expect(c.watermark_text).toBe('© Acme');
    expect(c.watermark_position).toBe('center');
    expect(c.watermark_opacity).toBe(0.5);
    expect(c.watermark_font_size).toBe(20);
  });

  it('forwards on-demand WebP claims (PHP parity)', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        webpEnabled: false,
        webpMaxWidth: 1600,
        webpDefaultQuality: 75,
        srcsetWidths: [400, 1200],
        srcsetSizes: '100vw',
      }),
    ) as Record<string, unknown>;
    expect(c.webp_enabled).toBe(false);
    expect(c.webp_max_width).toBe(1600);
    expect(c.webp_default_quality).toBe(75);
    expect(c.srcset_widths).toEqual([400, 1200]);
    expect(c.srcset_sizes).toBe('100vw');
  });

  it('forwards URL-import claims so the feature can be enabled (PHP parity)', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        allowUrlImport: true,
        maxImportMb: 20,
        importUrlAllowlist: ['*.unsplash.com'],
        importPath: 'imports',
        importRateLimit: 5,
        importConcurrency: 2,
      }),
    ) as Record<string, unknown>;
    expect(c.allow_url_import).toBe(true);
    expect(c.max_import_mb).toBe(20);
    expect(c.import_url_allowlist).toEqual(['*.unsplash.com']);
    expect(c.import_path).toBe('imports');
    expect(c.import_rate_limit).toBe(5);
    expect(c.import_concurrency).toBe(2);
  });

  it('rejects a secret shorter than 32 bytes', () => {
    expect(() => createToken({ secret: 'too-short', userId: 'u' })).toThrow(/at least 32 bytes/);
  });
});

describe('createByobToken', () => {
  const disk = { driver: 's3' as const, key: 'AK', secret: 'SK', bucket: 'b', region: 'us-east-1' };

  it('lists the byob disk names and embeds encrypted blobs (no max_storage/max_files)', () => {
    const token = createByobToken({ secret: SECRET, userId: 'u', byobDisks: { 'my-s3': disk } });
    const c = decodeToken(token);
    expect(c.disks).toEqual(['my-s3']);
    expect(c.byob_disks && Object.keys(c.byob_disks)).toEqual(['my-s3']);
    expect(c.max_storage).toBeUndefined();
    expect(c.max_files).toBeUndefined();
    expect(c.perms).toEqual(['read', 'write']);
  });

  it('rejects a non-s3 driver and missing fields', () => {
    expect(() =>
      createByobToken({ secret: SECRET, userId: 'u', byobDisks: { x: { ...disk, driver: 'local' as any } } }),
    ).toThrow(/driver must be "s3" or "sftp"/);
    expect(() =>
      createByobToken({ secret: SECRET, userId: 'u', byobDisks: { x: { ...disk, bucket: '' } } }),
    ).toThrow(/missing required "bucket"/);
  });

  it('accepts a BYOB SFTP disk (a user-owned VPS)', () => {
    const token = createByobToken({
      secret: SECRET,
      userId: 'u',
      byobDisks: {
        'my-vps': { driver: 'sftp', host: 'vps.example.com', username: 'deploy', password: 'pw', root: '/srv' },
      },
    });
    const c = decodeToken(token);
    expect(c.disks).toEqual(['my-vps']);
    expect(c.byob_disks && Object.keys(c.byob_disks)).toEqual(['my-vps']);
  });

  it('rejects a BYOB SFTP disk without host / username / auth', () => {
    expect(() =>
      createByobToken({ secret: SECRET, userId: 'u', byobDisks: { x: { driver: 'sftp', username: 'u', password: 'p' } as any } }),
    ).toThrow(/missing required "host"/);
    expect(() =>
      createByobToken({ secret: SECRET, userId: 'u', byobDisks: { x: { driver: 'sftp', host: 'h.example.com', username: 'u' } as any } }),
    ).toThrow(/needs a "password" or "private_key"/);
  });
});

describe('verifyToken', () => {
  it('round-trips and rejects tampering / expiry', () => {
    const token = createToken({ secret: SECRET, userId: 'u' });
    expect(verifyToken(token, SECRET).sub).toBe('u');
    expect(() => verifyToken(token, 'another-secret-that-is-32-bytes-min!!')).toThrow(/invalid token signature/);

    const expired = createToken({ secret: SECRET, userId: 'u', ttl: -10 });
    expect(() => verifyToken(expired, SECRET)).toThrow(/expired/);
  });
});

describe('BYOB encrypt/decrypt round-trip (Node ↔ Node)', () => {
  it('recovers the original config', () => {
    const cfg = { driver: 's3', key: 'AK', secret: 'SK', bucket: 'b', region: 'eu-west-1' };
    const blob = encryptByob(cfg, SECRET);
    expect(decryptByob(blob, SECRET)).toEqual(cfg);
  });
});
