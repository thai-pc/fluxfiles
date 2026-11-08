<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require_once __DIR__ . '/../../client/Config.php';
require_once __DIR__ . '/../src/Access.php';

use FluxFiles\LicenseServer\Admin\Access;
use FluxFiles\LicenseServer\Client\Config;

try {
    $issuer = Config::get('FLUXFILES_ADMIN_ACCESS_ISSUER');
    $file = Config::get('FLUXFILES_ADMIN_ACCESS_JWKS_FILE');
    if (!Access::issuer($issuer) || $file === '' || !is_dir(dirname($file)) || is_link($file)) {
        throw new RuntimeException();
    }
    $data = Access::downloadKeys($issuer);
    if ($data === null) {
        throw new RuntimeException();
    }
    umask(0027);
    $tmp = dirname($file) . '/.access-' . bin2hex(random_bytes(8));
    if (
        file_put_contents(
            $tmp,
            json_encode(['keys' => $data['keys']], JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        ) === false ||
        !chmod($tmp, 0640) ||
        !chgrp($tmp, (int) filegroup(dirname($file))) ||
        !rename($tmp, $file)
    ) {
        @unlink($tmp);
        throw new RuntimeException();
    }
    echo "Access verification keys refreshed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot refresh Access verification keys; existing keys were preserved.\n");
    exit(1);
}
