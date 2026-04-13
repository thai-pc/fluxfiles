<?php

/**
 * Test script for BYOB (Bring Your Own Bucket) feature.
 *
 * Usage:
 *   php tests/test-byob.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env from packages/core/ if present, otherwise fall back to repo root.
foreach ([__DIR__ . '/..', __DIR__ . '/../../..'] as $envDir) {
    if (is_file($envDir . '/.env')) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

require_once __DIR__ . '/../embed.php';

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

function assertContains($needle, array $haystack, string $msg = ''): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new \RuntimeException(
            $msg ?: json_encode($needle) . " not found in " . json_encode($haystack)
        );
    }
}

$secret = $_ENV['FLUXFILES_SECRET'] ?? '';
if ($secret === '') {
    echo "{$red}ERROR: FLUXFILES_SECRET not set in .env{$reset}\n";
    exit(1);
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles BYOB Test Suite                   ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► CredentialEncryptor{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('encrypt returns base64 string', function () use ($secret) {
    $config = ['driver' => 's3', 'bucket' => 'test', 'key' => 'k', 'secret' => 's'];
    $blob = FluxFiles\CredentialEncryptor::encrypt($config, $secret);
    assertEqual(false, empty($blob));
    // Must be valid base64
    assertEqual(true, base64_decode($blob, true) !== false);
});

test('decrypt returns original config', function () use ($secret) {
    $config = [
        'driver'   => 's3',
        'region'   => 'eu-west-1',
        'bucket'   => 'my-bucket-123',
        'key'      => 'AKIAIOSFODNN7EXAMPLE',
        'secret'   => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        'endpoint' => 'https://s3.custom.endpoint.com',
    ];
    $blob = FluxFiles\CredentialEncryptor::encrypt($config, $secret);
    $decrypted = FluxFiles\CredentialEncryptor::decrypt($blob, $secret);
    assertEqual($config, $decrypted);
});

test('each encryption produces unique ciphertext', function () use ($secret) {
    $config = ['driver' => 's3', 'bucket' => 'test', 'key' => 'k', 'secret' => 's'];
    $blob1 = FluxFiles\CredentialEncryptor::encrypt($config, $secret);
    $blob2 = FluxFiles\CredentialEncryptor::encrypt($config, $secret);
    if ($blob1 === $blob2) {
        throw new \RuntimeException('Two encryptions should not produce identical blobs (nonce must differ)');
    }
    // But both should decrypt to the same value
    assertEqual(
        FluxFiles\CredentialEncryptor::decrypt($blob1, $secret),
        FluxFiles\CredentialEncryptor::decrypt($blob2, $secret)
    );
});

test('tampered blob throws 401', function () use ($secret) {
    $config = ['driver' => 's3', 'bucket' => 'test', 'key' => 'k', 'secret' => 's'];
    $blob = FluxFiles\CredentialEncryptor::encrypt($config, $secret);
    $raw = base64_decode($blob);
    // Flip a byte in the middle
    $raw[strlen($raw) / 2] = chr(ord($raw[strlen($raw) / 2]) ^ 0xFF);
    $tampered = base64_encode($raw);
    try {
        FluxFiles\CredentialEncryptor::decrypt($tampered, $secret);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(401, $e->getCode());
    }
});

test('wrong secret throws 401', function () use ($secret) {
    $config = ['driver' => 's3', 'bucket' => 'test', 'key' => 'k', 'secret' => 's'];
    $blob = FluxFiles\CredentialEncryptor::encrypt($config, $secret);
    try {
        FluxFiles\CredentialEncryptor::decrypt($blob, 'totally-wrong-secret-key-123456');
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(401, $e->getCode());
    }
});

test('invalid base64 throws 401', function () use ($secret) {
    try {
        FluxFiles\CredentialEncryptor::decrypt('not-valid-base64!!!', $secret);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(401, $e->getCode());
    }
});

test('too short blob throws 401', function () use ($secret) {
    try {
        FluxFiles\CredentialEncryptor::decrypt(base64_encode('short'), $secret);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(401, $e->getCode());
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► CredentialEncryptor::validate{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('local driver rejected with 403', function () {
    try {
        FluxFiles\CredentialEncryptor::validate('bad', ['driver' => 'local', 'root' => '/etc']);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(403, $e->getCode());
    }
});

test('unknown driver rejected with 400', function () {
    try {
        FluxFiles\CredentialEncryptor::validate('d', ['driver' => 'ftp', 'bucket' => 'x', 'key' => 'k', 'secret' => 's']);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(400, $e->getCode());
    }
});

test('missing bucket rejected', function () {
    try {
        FluxFiles\CredentialEncryptor::validate('d', ['driver' => 's3', 'key' => 'k', 'secret' => 's']);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(400, $e->getCode());
    }
});

test('missing key rejected', function () {
    try {
        FluxFiles\CredentialEncryptor::validate('d', ['driver' => 's3', 'bucket' => 'b', 'secret' => 's']);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(400, $e->getCode());
    }
});

test('missing secret rejected', function () {
    try {
        FluxFiles\CredentialEncryptor::validate('d', ['driver' => 's3', 'bucket' => 'b', 'key' => 'k']);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(400, $e->getCode());
    }
});

test('valid S3 config passes validation', function () {
    FluxFiles\CredentialEncryptor::validate('my-s3', [
        'driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
    ]);
    // No exception = pass
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Claims with BYOB{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Claims without byob_disks is backward compatible', function () use ($secret) {
    $payload = (object) [
        'sub' => 'user-1',
        'perms' => ['read'],
        'disks' => ['local'],
        'prefix' => '',
        'max_upload' => 10,
    ];
    $claims = FluxFiles\Claims::fromJwtPayload($payload, $secret);
    assertEqual('user-1', $claims->userId);
    assertEqual(0, count($claims->byobDisks));
    assertEqual(false, $claims->isByobDisk('local'));
});

test('Claims decrypts BYOB disks from JWT payload', function () use ($secret) {
    $s3Config = ['driver' => 's3', 'bucket' => 'user-bucket', 'key' => 'AK', 'secret' => 'SK', 'region' => 'us-west-2'];
    $encrypted = FluxFiles\CredentialEncryptor::encrypt($s3Config, $secret);

    $payload = (object) [
        'sub' => 'user-byob',
        'perms' => ['read', 'write'],
        'disks' => ['local', 'my-s3'],
        'prefix' => '',
        'max_upload' => 10,
        'byob_disks' => (object) ['my-s3' => $encrypted],
    ];
    $claims = FluxFiles\Claims::fromJwtPayload($payload, $secret);

    assertEqual(true, $claims->isByobDisk('my-s3'));
    assertEqual(false, $claims->isByobDisk('local'));
    assertEqual('user-bucket', $claims->getByobConfig('my-s3')['bucket']);
    assertEqual('AK', $claims->getByobConfig('my-s3')['key']);
    assertEqual(null, $claims->getByobConfig('local'));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► DiskManager BYOB registration{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('registerByobDisk adds S3 config', function () {
    $dm = new FluxFiles\DiskManager([]);
    $dm->registerByobDisk('my-s3', [
        'driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
    ]);
    assertEqual('s3', $dm->config('my-s3')['driver']);
    assertEqual('b', $dm->config('my-s3')['bucket']);
});

test('registerByobDisk rejects local driver', function () {
    $dm = new FluxFiles\DiskManager([]);
    try {
        $dm->registerByobDisk('evil', ['driver' => 'local', 'root' => '/etc']);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(403, $e->getCode());
    }
});

test('registerByobDisk overrides existing disk', function () {
    $dm = new FluxFiles\DiskManager([
        'my-s3' => ['driver' => 's3', 'bucket' => 'old', 'key' => 'old', 'secret' => 'old'],
    ]);
    $dm->registerByobDisk('my-s3', [
        'driver' => 's3', 'bucket' => 'new', 'key' => 'new', 'secret' => 'new',
    ]);
    assertEqual('new', $dm->config('my-s3')['bucket']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Token generation functions{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('fluxfiles_byob_token creates valid JWT with encrypted credentials', function () use ($secret) {
    $token = fluxfiles_byob_token(
        userId: 'byob-user',
        byobDisks: [
            'my-s3' => ['driver' => 's3', 'bucket' => 'ub', 'key' => 'uk', 'secret' => 'us', 'region' => 'eu-west-1'],
        ],
        perms: ['read', 'write', 'delete']
    );

    $payload = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret, 'HS256'));
    assertContains('my-s3', (array) $payload->disks);
    assertEqual(true, isset($payload->byob_disks->{'my-s3'}));

    // Full roundtrip
    $claims = FluxFiles\Claims::fromJwtPayload($payload, $secret);
    assertEqual('byob-user', $claims->userId);
    assertEqual(true, $claims->isByobDisk('my-s3'));
    assertEqual('ub', $claims->getByobConfig('my-s3')['bucket']);
});

test('fluxfiles_mixed_token includes both server and BYOB disks', function () use ($secret) {
    $token = fluxfiles_mixed_token(
        userId: 'mixed-user',
        serverDisks: ['local'],
        byobDisks: [
            'user-r2' => ['driver' => 's3', 'endpoint' => 'https://x.r2.cloudflarestorage.com', 'bucket' => 'rb', 'key' => 'rk', 'secret' => 'rs', 'region' => 'auto'],
        ]
    );

    $payload = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret, 'HS256'));
    $disks = (array) $payload->disks;
    assertContains('local', $disks);
    assertContains('user-r2', $disks);

    $claims = FluxFiles\Claims::fromJwtPayload($payload, $secret);
    assertEqual(false, $claims->isByobDisk('local'));
    assertEqual(true, $claims->isByobDisk('user-r2'));
    assertEqual('rb', $claims->getByobConfig('user-r2')['bucket']);
});

test('fluxfiles_byob_token has shorter TTL (1800s default)', function () use ($secret) {
    $token = fluxfiles_byob_token(
        userId: 'ttl-user',
        byobDisks: [
            's' => ['driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's'],
        ]
    );

    $payload = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret, 'HS256'));
    $ttl = $payload->exp - $payload->iat;
    assertEqual(1800, $ttl, "Expected TTL 1800 but got {$ttl}");
});

test('fluxfiles_byob_token rejects local driver in byobDisks', function () {
    try {
        fluxfiles_byob_token(
            userId: 'evil',
            byobDisks: ['hack' => ['driver' => 'local', 'root' => '/etc']],
        );
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(403, $e->getCode());
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Full JWT roundtrip (token → decode → Claims → DiskManager){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('end-to-end BYOB flow', function () use ($secret) {
    // 1. Host app creates BYOB token
    $token = fluxfiles_mixed_token(
        userId: 'e2e-user',
        serverDisks: ['local'],
        byobDisks: [
            'my-s3' => [
                'driver' => 's3',
                'region' => 'ap-southeast-1',
                'bucket' => 'customer-prod',
                'key'    => 'AKIA_REAL_KEY',
                'secret' => 'real_secret_value',
            ],
        ],
        perms: ['read', 'write', 'delete']
    );

    // 2. Server decodes JWT (simulating JwtMiddleware)
    $payload = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret, 'HS256'));
    $claims = FluxFiles\Claims::fromJwtPayload($payload, $secret);

    // 3. Server creates DiskManager and registers BYOB disks
    $diskConfigs = ['local' => ['driver' => 'local', 'root' => '/tmp/test']];
    $dm = new FluxFiles\DiskManager($diskConfigs);
    foreach ($claims->byobDisks as $name => $cfg) {
        $dm->registerByobDisk($name, $cfg);
    }

    // 4. Verify
    assertEqual('e2e-user', $claims->userId);
    assertEqual(true, $claims->hasDisk('local'));
    assertEqual(true, $claims->hasDisk('my-s3'));
    assertEqual(false, $claims->isByobDisk('local'));
    assertEqual(true, $claims->isByobDisk('my-s3'));
    assertEqual('customer-prod', $dm->config('my-s3')['bucket']);
    assertEqual('AKIA_REAL_KEY', $dm->config('my-s3')['key']);
    assertEqual('local', $dm->config('local')['driver']);
});

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "{$cyan}  Results: {$green}{$passed} passed{$reset}";
if ($failed > 0) {
    echo ", {$red}{$failed} failed{$reset}";
}
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
