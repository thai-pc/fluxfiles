import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';
import { createToken, createByobToken, verifyToken, decodeToken } from '../src';
import { encryptByob, decryptByob } from '../src/crypto';

const SECRET = 'test-secret-key-that-is-at-least-32-bytes-long';

// Shared cross-language fixture (docs/testdata/token-vectors.json,
// docs/PYTHON-TOKEN-SDK-DESIGN.md §6.1) — role/edition presets and the generic
// `claims` escape-hatch precedence, loaded here and by PHP's test-role-preset.php
// (and, eventually, the Python SDK's own suite) so all three mint the exact same
// vectors instead of hand-copying them per language.
interface TokenVector {
  name: string;
  input: { user_id: string; role?: string; edition?: string; ttl_seconds?: number; claims?: Record<string, unknown> };
  expect?: Record<string, unknown>;
  expect_true?: string[];
  expect_absent?: string[];
}

// BYOB + role/edition vectors (docs/PYTHON-TOKEN-SDK-DESIGN.md §5.1/§6.1) — a BYOB
// token minted with `role`/`edition` must carry both the preset's claim bundle and the
// encrypted `byob_disks` claim, per the 8-step merge order createByobToken() now follows.
interface ByobRoleVector {
  name: string;
  input: { user_id: string; role?: string; edition?: string; byob_disks: Record<string, Record<string, unknown>> };
  expect?: Record<string, unknown>;
  expect_true?: string[];
  expect_byob_disks_present?: boolean;
}

const VECTORS_PATH = resolve(process.cwd(), '../../docs/testdata/token-vectors.json');
const VECTORS = JSON.parse(readFileSync(VECTORS_PATH, 'utf8')) as {
  plain_tokens: TokenVector[];
  role_presets: TokenVector[];
  edition_presets: TokenVector[];
  byob_role_presets: ByobRoleVector[];
};

/**
 * Mint a vector's `input` via createToken() and assert its expect/expect_true/
 * expect_absent fields against the decoded claims. `<claim>_present` asserts
 * effective presence (=== true), not literal key presence — matches the PHP
 * harness, since "absent" and "present-but-false" both mean "not enabled" for
 * every boolean claim these fixtures cover.
 */
function assertVector(v: TokenVector): void {
  const c = decodeToken(
    createToken({
      secret: SECRET,
      userId: v.input.user_id,
      role: v.input.role as any,
      edition: v.input.edition as any,
      ttl: v.input.ttl_seconds,
      claims: v.input.claims,
    }),
  ) as Record<string, unknown>;

  for (const [key, expected] of Object.entries(v.expect ?? {})) {
    if (key.endsWith('_present')) {
      const claim = key.slice(0, -'_present'.length);
      expect(c[claim] === true).toBe(expected);
      continue;
    }
    if (key === 'ttl_seconds') {
      expect((c.exp as number) - (c.iat as number)).toBe(expected);
      continue;
    }
    // Raw comparison — a genuinely absent claim is `undefined`, NOT coerced to
    // `false`. Coercing here would make an omitted key indistinguishable from
    // an explicit `false`, which is exactly the historical B1 bug
    // (allow_extract/allow_chmod default to TRUE when absent) — mirrors
    // assertByobRoleVector below, which never coerced.
    expect(c[key]).toEqual(expected);
  }
  for (const claim of v.expect_true ?? []) {
    expect(c[claim]).toBe(true);
  }
  for (const claim of v.expect_absent ?? []) {
    expect(c[claim]).toBeUndefined();
  }
}

describe('createToken', () => {
  for (const v of VECTORS.plain_tokens) {
    it(`plain token vector: ${v.name}`, () => assertVector(v));
  }

  for (const v of VECTORS.edition_presets) {
    it(`edition preset vector: ${v.name}`, () => assertVector(v));
  }

  it('claims escape hatch sets any raw snake_case claim; explicit wins', () => {
    const c = decodeToken(createToken({
      secret: SECRET, userId: 'u', edition: 'pro',
      claims: { allow_terminal: true, terminal_pty_url: 'https://t.example.com/', upload_collision: 'overwrite', allow_optimize: false },
    }));
    expect(c.allow_terminal).toBe(true);
    expect(c.terminal_pty_url).toBe('https://t.example.com/');
    expect(c.upload_collision).toBe('overwrite');
    // explicit claim overrides the edition preset (pro defaults allow_optimize true)
    expect(c.allow_optimize).toBe(false);
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

  it('forwards allow_terminal (SSH terminal) when set, omits it otherwise', () => {
    const on = decodeToken(createToken({ secret: SECRET, userId: 'u', allowTerminal: true })) as Record<string, unknown>;
    expect(on.allow_terminal).toBe(true);
    const off = decodeToken(createToken({ secret: SECRET, userId: 'u' })) as Record<string, unknown>;
    expect(off.allow_terminal).toBeUndefined();
  });

  it('forwards the four Share landing claims (PHP parity), omits them otherwise', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        allowShare: true,
        shareUrlTtl: 120,
        shareBaseUrl: 'https://files.acme.com/public/share.html',
        sharePreview: false,
        shareAnalytics: true,
      }),
    ) as Record<string, unknown>;
    expect(c.allow_share).toBe(true);
    expect(c.share_url_ttl).toBe(120);
    expect(c.share_base_url).toBe('https://files.acme.com/public/share.html');
    expect(c.share_preview).toBe(false);
    expect(c.share_analytics).toBe(true);
    // Absent = inherit the core defaults (60s / request origin / preview on / analytics off).
    const off = decodeToken(createToken({ secret: SECRET, userId: 'u' })) as Record<string, unknown>;
    expect(off.share_url_ttl).toBeUndefined();
    expect(off.share_base_url).toBeUndefined();
    expect(off.share_preview).toBeUndefined();
    expect(off.share_analytics).toBeUndefined();
  });

  it('forwards intakeBaseUrl (PHP parity), and the raw claim escape hatch', () => {
    const c = decodeToken(
      createToken({
        secret: SECRET,
        userId: 'u',
        allowIntake: true,
        intakeBaseUrl: 'https://files.acme.com/public/intake.html',
      }),
    ) as Record<string, unknown>;
    expect(c.allow_intake).toBe(true);
    expect(c.intake_base_url).toBe('https://files.acme.com/public/intake.html');
    // Absent = inherit the core default (the request origin + /public/intake.html).
    const off = decodeToken(createToken({ secret: SECRET, userId: 'u' })) as Record<string, unknown>;
    expect(off.intake_base_url).toBeUndefined();
    // Raw name passthrough (the documented escape hatch for any claim).
    const raw = decodeToken(
      createToken({ secret: SECRET, userId: 'u', claims: { intake_base_url: 'https://x/i' } }),
    ) as Record<string, unknown>;
    expect(raw.intake_base_url).toBe('https://x/i');
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

describe('role preset (docs/ACL-ROLE-PRESETS-DESIGN.md)', () => {
  for (const v of VECTORS.role_presets) {
    it(v.name, () => assertVector(v));
  }
});

/**
 * Mint a `byob_role_presets` vector via createByobToken() and assert its expect/
 * expect_true fields plus `byob_disks` presence/round-trip. Shared with PHP's
 * test-byob.php so both languages exercise the exact same vectors.
 */
function assertByobRoleVector(v: ByobRoleVector): void {
  const token = createByobToken({
    secret: SECRET,
    userId: v.input.user_id,
    byobDisks: v.input.byob_disks as any,
    role: v.input.role as any,
    edition: v.input.edition as any,
  });
  const c = decodeToken(token) as Record<string, unknown>;

  for (const [key, expected] of Object.entries(v.expect ?? {})) {
    expect(c[key]).toEqual(expected);
  }
  for (const claim of v.expect_true ?? []) {
    expect(c[claim]).toBe(true);
  }
  if (v.expect_byob_disks_present) {
    const byobDisks = (c.byob_disks ?? {}) as Record<string, string>;
    expect(Object.keys(byobDisks).length).toBeGreaterThan(0);
    // Round-trip: each disk's blob must decrypt back to its original config.
    for (const [name, blob] of Object.entries(byobDisks)) {
      expect(decryptByob(blob, SECRET)).toEqual(v.input.byob_disks[name]);
    }
  }
}

describe('BYOB + role/edition presets (docs/PYTHON-TOKEN-SDK-DESIGN.md §5.1)', () => {
  for (const v of VECTORS.byob_role_presets) {
    it(v.name, () => assertByobRoleVector(v));
  }

  // Kept inline (not in the shared fixture, per §6.2's scoping note): this asserts
  // Node's own explicit-kwarg-overrides-preset merge order, not a cross-language value.
  it('an explicit perms kwarg overrides role="viewer" on a BYOB token', () => {
    const c = decodeToken(
      createByobToken({
        secret: SECRET,
        userId: 'u',
        role: 'viewer',
        perms: ['read', 'write', 'delete'],
        byobDisks: { 'my-s3': { driver: 's3', key: 'AK', secret: 'SK', bucket: 'b', region: 'us-east-1' } },
      }),
    ) as Record<string, unknown>;
    expect(c.perms).toEqual(['read', 'write', 'delete']);
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
