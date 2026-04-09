<?php

declare(strict_types=1);

return [
    'local' => [
        'driver' => 'local',
        'root'   => __DIR__ . '/../storage/uploads',
        'url'    => '/storage/uploads',
    ],
    's3' => [
        'driver' => 's3',
        'region' => $_ENV['AWS_DEFAULT_REGION'] ?? 'ap-southeast-1',
        'bucket' => $_ENV['AWS_BUCKET'] ?? '',
        'key'    => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
    ],
    'r2' => [
        'driver'   => 's3',
        'endpoint' => 'https://' . ($_ENV['R2_ACCOUNT_ID'] ?? '') . '.r2.cloudflarestorage.com',
        'region'   => 'auto',
        'bucket'   => $_ENV['R2_BUCKET'] ?? '',
        'key'      => $_ENV['R2_ACCESS_KEY_ID'] ?? '',
        'secret'   => $_ENV['R2_SECRET_ACCESS_KEY'] ?? '',
    ],
];
