<?php

require_once __DIR__ . '/../embed.php';

// Load .env from packages/core/ if present, otherwise fall back to repo root.
foreach ([__DIR__ . '/..', __DIR__ . '/../../..'] as $envDir) {
    if (is_file($envDir . '/.env')) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

if (($_ENV['FLUXFILES_SECRET'] ?? '') === '') {
    fwrite(STDERR, "ERROR: FLUXFILES_SECRET is not set. Define it in .env before generating tokens.\n");
    exit(1);
}

// Token day du quyen
$fullToken = fluxfiles_token(
    userId:      'test-user-001',
    perms:       ['read', 'write', 'delete'],
    disks:       ['local', 's3', 'r2'],
    prefix:      '',
    maxUploadMb: 30,
    allowedExt:  null,
    ttl:         86400
);
echo "FULL TOKEN:\n{$fullToken}\n\n";

// Token chi doc
$readToken = fluxfiles_token(
    userId: 'reader-001',
    perms:  ['read'],
    disks:  ['local'],
    ttl:    3600
);
echo "READ-ONLY TOKEN:\n{$readToken}\n\n";

// Token gioi han extension
$imageToken = fluxfiles_token(
    userId:      'uploader-001',
    perms:       ['read', 'write'],
    disks:       ['local'],
    allowedExt:  ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    maxUploadMb: 5,
    ttl:         3600
);
echo "IMAGE-ONLY TOKEN:\n{$imageToken}\n\n";

// Token co path prefix (scoped)
$scopedToken = fluxfiles_token(
    userId: 'scoped-user',
    perms:  ['read', 'write', 'delete'],
    disks:  ['local'],
    prefix: 'users/scoped-user/',
    ttl:    3600
);
echo "SCOPED TOKEN (prefix=users/scoped-user/):\n{$scopedToken}\n\n";

// Token co quota nho
$quotaToken = fluxfiles_token(
    userId:      'quota-user',
    perms:       ['read', 'write'],
    disks:       ['local'],
    maxUploadMb: 2,
    ttl:         3600
);
echo "QUOTA TOKEN (max_upload=2MB):\n{$quotaToken}\n\n";

// ── BYOB (Bring Your Own Bucket) ──

// Token BYOB: user dung S3 bucket rieng cua ho
$byobToken = fluxfiles_byob_token(
    userId:    'byob-user-001',
    byobDisks: [
        'my-s3' => [
            'driver' => 's3',
            'region' => 'us-west-2',
            'bucket' => 'user-personal-bucket',
            'key'    => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ],
    ],
    perms: ['read', 'write', 'delete'],
    ttl:   1800
);
echo "BYOB TOKEN (my-s3 = user's own bucket):\n{$byobToken}\n\n";

// Token Mixed: local (server) + my-r2 (user's Cloudflare R2)
$mixedToken = fluxfiles_mixed_token(
    userId:      'mixed-user-001',
    serverDisks: ['local'],
    byobDisks:   [
        'my-r2' => [
            'driver'   => 's3',
            'endpoint' => 'https://abc123.r2.cloudflarestorage.com',
            'region'   => 'auto',
            'bucket'   => 'user-r2-bucket',
            'key'      => 'R2_KEY_EXAMPLE',
            'secret'   => 'R2_SECRET_EXAMPLE',
        ],
    ],
    perms: ['read', 'write'],
    ttl:   1800
);
echo "MIXED TOKEN (local + my-r2):\n{$mixedToken}\n";
