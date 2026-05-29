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

// NOTE: arguments are positional (not named) so this script also runs on PHP 7.4.
// fluxfiles_token(userId, perms, disks, prefix, maxUploadMb, allowedExt, ttl, ownerOnly)

// Token day du quyen
$fullToken = fluxfiles_token(
    'test-user-001',                  // userId
    ['read', 'write', 'delete'],      // perms
    ['local', 's3', 'r2'],            // disks
    '',                               // prefix
    30,                               // maxUploadMb
    null,                             // allowedExt
    86400                             // ttl
);
echo "FULL TOKEN:\n{$fullToken}\n\n";

// Token chi doc
$readToken = fluxfiles_token(
    'reader-001',                     // userId
    ['read'],                         // perms
    ['local'],                        // disks
    '',                               // prefix
    10,                               // maxUploadMb
    null,                             // allowedExt
    3600                              // ttl
);
echo "READ-ONLY TOKEN:\n{$readToken}\n\n";

// Token gioi han extension
$imageToken = fluxfiles_token(
    'uploader-001',                   // userId
    ['read', 'write'],                // perms
    ['local'],                        // disks
    '',                               // prefix
    5,                                // maxUploadMb
    ['jpg', 'jpeg', 'png', 'webp', 'gif'], // allowedExt
    3600                              // ttl
);
echo "IMAGE-ONLY TOKEN:\n{$imageToken}\n\n";

// Token co path prefix (scoped)
$scopedToken = fluxfiles_token(
    'scoped-user',                    // userId
    ['read', 'write', 'delete'],      // perms
    ['local'],                        // disks
    'users/scoped-user/',             // prefix
    10,                               // maxUploadMb
    null,                             // allowedExt
    3600                              // ttl
);
echo "SCOPED TOKEN (prefix=users/scoped-user/):\n{$scopedToken}\n\n";

// Token co quota nho
$quotaToken = fluxfiles_token(
    'quota-user',                     // userId
    ['read', 'write'],                // perms
    ['local'],                        // disks
    '',                               // prefix
    2,                                // maxUploadMb
    null,                             // allowedExt
    3600                              // ttl
);
echo "QUOTA TOKEN (max_upload=2MB):\n{$quotaToken}\n\n";

// ── BYOB (Bring Your Own Bucket) ──
// fluxfiles_byob_token(userId, byobDisks, perms, prefix, maxUploadMb, allowedExt, ttl, ownerOnly)

// Token BYOB: user dung S3 bucket rieng cua ho
$byobToken = fluxfiles_byob_token(
    'byob-user-001',                  // userId
    [                                 // byobDisks
        'my-s3' => [
            'driver' => 's3',
            'region' => 'us-west-2',
            'bucket' => 'user-personal-bucket',
            'key'    => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ],
    ],
    ['read', 'write', 'delete'],      // perms
    '',                               // prefix
    10,                               // maxUploadMb
    null,                             // allowedExt
    1800                              // ttl
);
echo "BYOB TOKEN (my-s3 = user's own bucket):\n{$byobToken}\n\n";

// Token Mixed: local (server) + my-r2 (user's Cloudflare R2)
// fluxfiles_mixed_token(userId, serverDisks, byobDisks, perms, prefix, maxUploadMb, allowedExt, ttl, ownerOnly)
$mixedToken = fluxfiles_mixed_token(
    'mixed-user-001',                 // userId
    ['local'],                        // serverDisks
    [                                 // byobDisks
        'my-r2' => [
            'driver'   => 's3',
            'endpoint' => 'https://abc123.r2.cloudflarestorage.com',
            'region'   => 'auto',
            'bucket'   => 'user-r2-bucket',
            'key'      => 'R2_KEY_EXAMPLE',
            'secret'   => 'R2_SECRET_EXAMPLE',
        ],
    ],
    ['read', 'write'],                // perms
    '',                               // prefix
    10,                               // maxUploadMb
    null,                             // allowedExt
    1800                              // ttl
);
echo "MIXED TOKEN (local + my-r2):\n{$mixedToken}\n";
