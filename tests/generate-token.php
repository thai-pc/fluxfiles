<?php

require_once __DIR__ . '/../embed.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

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
echo "QUOTA TOKEN (max_upload=2MB):\n{$quotaToken}\n";
