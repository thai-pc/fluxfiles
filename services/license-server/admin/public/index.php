<?php

declare(strict_types=1);

// PHP's development router also serves assets through the hardening checks. In
// production, nginx must attach the same headers to its static asset location.
ini_set('display_errors','0');
require_once __DIR__ . '/../src/App.php';
(new \FluxFiles\LicenseServer\Admin\App())->run();
