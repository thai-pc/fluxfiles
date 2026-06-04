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
    ).toThrow(/driver must be "s3"/);
    expect(() =>
      createByobToken({ secret: SECRET, userId: 'u', byobDisks: { x: { ...disk, bucket: '' } } }),
    ).toThrow(/missing required "bucket"/);
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
