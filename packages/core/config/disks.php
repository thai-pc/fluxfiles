<?php

declare(strict_types=1);

return [
    'local' => [
        'driver' => 'local',
        'root'   => __DIR__ . '/../storage/uploads',
        'url'    => '/storage/uploads',
        // Gated media: when true, files are served through per-file /api/fm/stream
        // tokens (Range-capable) instead of the static `url`. The disk root must
        // then NOT be served statically by the web server. Default off.
        'private' => ($_ENV['FLUXFILES_LOCAL_PRIVATE'] ?? '') === 'true',
    ],
    's3' => [
        'driver'     => 's3',
        'region'     => $_ENV['AWS_DEFAULT_REGION'] ?? 'ap-southeast-1',
        'bucket'     => $_ENV['AWS_BUCKET'] ?? '',
        'key'        => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
        'secret'     => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
        // Custom S3-compatible endpoint (MinIO, DigitalOcean Spaces, …). Empty =
        // native AWS S3. When set, path-style addressing is used automatically.
        'endpoint'   => $_ENV['AWS_ENDPOINT'] ?? '',
        // 'public'  → direct object URLs (writes use public-read ACL).
        // 'private' → short-lived presigned GET URLs (default; safe on private buckets).
        'visibility' => $_ENV['AWS_VISIBILITY'] ?? 'private',
        // Optional CDN / custom domain base for public disks, e.g. https://cdn.example.com
        'public_url' => $_ENV['AWS_PUBLIC_URL'] ?? '',
    ],
    'r2' => [
        'driver'     => 's3',
        'endpoint'   => 'https://' . ($_ENV['R2_ACCOUNT_ID'] ?? '') . '.r2.cloudflarestorage.com',
        'region'     => 'auto',
        'bucket'     => $_ENV['R2_BUCKET'] ?? '',
        'key'        => $_ENV['R2_ACCESS_KEY_ID'] ?? '',
        'secret'     => $_ENV['R2_SECRET_ACCESS_KEY'] ?? '',
        // R2 has no object ACLs: 'public' relies on a public bucket + public_url
        // (r2.dev or a custom domain); 'private' (default) serves presigned GET URLs.
        'visibility' => $_ENV['R2_VISIBILITY'] ?? 'private',
        'public_url' => $_ENV['R2_PUBLIC_URL'] ?? '',
    ],
];
