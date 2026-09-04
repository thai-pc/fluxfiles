<?php

/**
 * SSH ControlMaster (`SshMultiplexer` + `DiskManager`'s multiplex helpers) —
 * pure-PHP, no live SSH. Mirrors `test-terminal.php`'s style and
 * `test-sftp-passphrase.php`'s reflection-on-private-statics convention. Locks
 * in the security-relevant pure logic from docs/SFTP-CONTROLMASTER-SPEC.md §18:
 *
 *   F1 — cache-key derivation can't collide across different credentials.
 *   F2 — socket dir/filename hygiene (0700, no host/user leakage, sun_path guard).
 *   F4 — key-based-auth-only eligibility gate (password/passphrase fall back).
 *   F5 — the phpseclib-shaped algorithm list and the OpenSSH -o flags stay in sync.
 *   F6 — the LRU eviction decision is a pure, correct function.
 *   known_hosts line format (piggybacked off phpseclib's verified host key).
 *   env round-trip for the new `ssh_multiplex` disk config key.
 *
 * The live `ssh -M` mechanics (F3 ControlPersist expiry, actual proc_open,
 * password-disk end-to-end fallback) are covered separately and env-gated in
 * tests/integration/test-ssh-multiplex-live.php.
 *
 * Usage: php tests/unit/test-ssh-multiplex.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\SshMultiplexer;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertSameSet(array $expected, array $actual, string $m = ''): void {
    $e = $expected; $a = $actual;
    sort($e); sort($a);
    if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'sets differ: expected ' . json_encode($e) . ' got ' . json_encode($a));
}

/** Reflected call to a private/protected static method. */
function callPrivateStatic(string $class, string $method, array $args = []) {
    $m = new \ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}
/** Reflected call to a private/protected instance method. */
function callPrivateMethod(object $obj, string $method, array $args = []) {
    $m = new \ReflectionMethod(get_class($obj), $method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
}

function muxCacheKey(array $cfg): string {
    return callPrivateStatic(SshMultiplexer::class, 'cacheKey', [$cfg]);
}
function muxSocketPath(string $hash, string $dir): string {
    return callPrivateStatic(SshMultiplexer::class, 'socketPath', [$hash, $dir]);
}
function multiplexEligible(array $cfg): bool {
    return callPrivateStatic(DiskManager::class, 'multiplexEligible', [$cfg]);
}

echo "\n{$cyan}══ SSH ControlMaster (SshMultiplexer / DiskManager) ══{$reset}\n\n";

// ─────────────────────────────────────────────────────────────────────────
// F1 — cache-key derivation (multi-tenant collision safety)
// ─────────────────────────────────────────────────────────────────────────

$base = [
    'host' => 'example.com', 'port' => 22, 'username' => 'deploy',
    'password' => '', 'private_key' => 'KEY-A', 'private_key_passphrase' => '',
    'host_fingerprint' => '', 'strict_algorithms' => false,
];

test('F1: differing password → different cache key', function () use ($base) {
    $a = muxCacheKey($base);
    $b = muxCacheKey(array_merge($base, ['password' => 'secret']));
    assertTrue($a !== $b, 'password change must change the cache key');
});

test('F1: differing private_key → different cache key', function () use ($base) {
    $a = muxCacheKey($base);
    $b = muxCacheKey(array_merge($base, ['private_key' => 'KEY-B']));
    assertTrue($a !== $b, 'private_key change must change the cache key');
});

test('F1: differing host_fingerprint → different cache key', function () use ($base) {
    $a = muxCacheKey($base);
    $b = muxCacheKey(array_merge($base, ['host_fingerprint' => 'aa:bb:cc']));
    assertTrue($a !== $b, 'host_fingerprint change must change the cache key (so a config edit is never reused)');
});

test('F1: differing strict_algorithms → different cache key', function () use ($base) {
    $a = muxCacheKey($base);
    $b = muxCacheKey(array_merge($base, ['strict_algorithms' => true]));
    assertTrue($a !== $b, 'strict_algorithms change must change the cache key');
});

test('F1: fully identical configs (the genuinely-same-login case) → SAME cache key', function () use ($base) {
    $c1 = ['host' => 'example.com', 'port' => 22, 'username' => 'deploy', 'password' => '', 'private_key' => 'KEY-A', 'private_key_passphrase' => '', 'host_fingerprint' => '', 'strict_algorithms' => false];
    $c2 = ['host' => 'example.com', 'port' => 22, 'username' => 'deploy', 'password' => '', 'private_key' => 'KEY-A', 'private_key_passphrase' => '', 'host_fingerprint' => '', 'strict_algorithms' => false];
    assertEqual(muxCacheKey($c1), muxCacheKey($c2), 'byte-identical configs must share one cache key/socket');
});

// ─────────────────────────────────────────────────────────────────────────
// F2 — socket dir/filename hygiene
// ─────────────────────────────────────────────────────────────────────────

test('F2: ensureOwnedDir creates a fresh directory at mode 0700', function () {
    $dir = sys_get_temp_dir() . '/ff-mux-dir-' . bin2hex(random_bytes(4));
    try {
        $ok = callPrivateStatic(SshMultiplexer::class, 'ensureOwnedDir', [$dir]);
        assertTrue($ok, 'ensureOwnedDir() succeeds for a fresh dir it can create');
        assertTrue(is_dir($dir), 'directory actually created');
        $mode = fileperms($dir) & 0777;
        assertEqual(0700, $mode, sprintf('mode is 0700, got 0%o', $mode));
    } finally {
        @rmdir($dir);
    }
});

test('F2: computed socket filename never contains the host/username substrings', function () {
    $cfg = [
        'host' => 'supersecrethost.example.internal', 'port' => 22,
        'username' => 'sensitive-deploy-user', 'private_key' => 'K', 'private_key_passphrase' => '',
    ];
    $hash = muxCacheKey($cfg);
    $path = muxSocketPath($hash, '/var/lib/fluxfiles/ssh-sockets');
    assertTrue(stripos($path, 'supersecrethost') === false, 'no host substring in the socket path');
    assertTrue(stripos($path, 'sensitive-deploy-user') === false, 'no username substring in the socket path');
    assertTrue(str_ends_with($path, '.sock'), 'ends with .sock');
    assertEqual(20, strlen(basename($path, '.sock')), 'filename stem is exactly 20 hex chars (80 bits)');
});

test('F2: a long FLUXFILES_STORAGE_PATH triggers the >100-byte sun_path guard BEFORE any proc_open', function () {
    $savedEnv = $_ENV['FLUXFILES_STORAGE_PATH'] ?? null;
    $binProp = new \ReflectionProperty(SshMultiplexer::class, 'sshBinary');
    $binProp->setAccessible(true);
    $savedBin = $binProp->getValue();

    // Force sshBinary() to resolve without touching the real lookup, so this test
    // is deterministic regardless of whether `ssh` is installed in CI — we're
    // isolating the sun_path length guard, not `ssh` availability (that's a
    // separate infra-fallback path, not under test here).
    $binProp->setValue(null, '/usr/bin/true');

    $longBase = sys_get_temp_dir() . '/' . str_repeat('x', 90) . '-ffmux';
    $_ENV['FLUXFILES_STORAGE_PATH'] = $longBase;

    try {
        $dm = new DiskManager([]);
        $cfg = [
            'driver' => 'sftp', 'host' => 'h', 'port' => 22, 'username' => 'u',
            'private_key' => 'KEY', 'private_key_passphrase' => '', 'ssh_multiplex' => true,
        ];
        $mux = SshMultiplexer::acquire($cfg, 'sftp', $dm);
        $result = callPrivateMethod($mux, 'tryMultiplexed', ['echo hi', '.', 5]);

        assertTrue($result === null, 'tryMultiplexed() returns null (caller falls back to phpseclib)');
        assertTrue(is_dir($longBase . '/ssh-sockets'), 'the socket BASE dir is created (that check runs before the length guard)');
        assertTrue(!is_dir($longBase . '/ssh-sockets/keys'), 'keys/ is NEVER created — proves execCold() (and its proc_open) was never reached');
        assertEqual([], glob($longBase . '/ssh-sockets/*.sock') ?: [], 'no socket file was ever created');
    } finally {
        if ($savedEnv === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $savedEnv; }
        $binProp->setValue(null, $savedBin);
        @exec('rm -rf ' . escapeshellarg($longBase));
    }
});

// ─────────────────────────────────────────────────────────────────────────
// F4 — key-based-auth-only eligibility gate (password/passphrase fall back)
// ─────────────────────────────────────────────────────────────────────────

test('F4: ssh_multiplex unset → ineligible', function () {
    assertTrue(multiplexEligible(['driver' => 'sftp', 'private_key' => 'KEY']) === false);
});

test('F4: ssh_multiplex true, non-sftp driver → ineligible', function () {
    assertTrue(multiplexEligible(['driver' => 's3', 'ssh_multiplex' => true, 'private_key' => 'KEY']) === false);
});

test('F4: ssh_multiplex true + no private_key (password-only) → ineligible', function () {
    assertTrue(multiplexEligible(['driver' => 'sftp', 'ssh_multiplex' => true, 'password' => 'pw']) === false);
});

test('F4: ssh_multiplex true + private_key + NON-empty passphrase → ineligible', function () {
    assertTrue(multiplexEligible([
        'driver' => 'sftp', 'ssh_multiplex' => true,
        'private_key' => 'KEY', 'private_key_passphrase' => 'secret',
    ]) === false);
});

test('F4: ssh_multiplex true + private_key + EMPTY passphrase → eligible', function () {
    assertTrue(multiplexEligible([
        'driver' => 'sftp', 'ssh_multiplex' => true,
        'private_key' => 'KEY', 'private_key_passphrase' => '',
    ]) === true);
});

test('F4: multiplexHandle() returns null for an ineligible (password-only) disk', function () {
    $dm = new DiskManager(['sftp' => [
        'driver' => 'sftp', 'host' => 'h', 'username' => 'u', 'password' => 'pw', 'ssh_multiplex' => true,
    ]]);
    assertTrue($dm->multiplexHandle('sftp') === null);
});

test('F4: multiplexHandle() returns [SshMultiplexer, root] for an eligible disk', function () {
    $dm = new DiskManager(['sftp' => [
        'driver' => 'sftp', 'host' => 'h', 'username' => 'u',
        'private_key' => 'KEY', 'private_key_passphrase' => '', 'ssh_multiplex' => true, 'root' => '/srv/app',
    ]]);
    $handle = $dm->multiplexHandle('sftp');
    assertTrue($handle !== null, 'eligible disk returns a handle');
    assertTrue($handle[0] instanceof SshMultiplexer, 'handle[0] is an SshMultiplexer');
    assertEqual('/srv/app', $handle[1], 'handle[1] is the disk root, trailing slash trimmed');
});

// ─────────────────────────────────────────────────────────────────────────
// F5 — algorithm-list sync (one source feeds both phpseclib + OpenSSH shapes)
// ─────────────────────────────────────────────────────────────────────────

test('F5: modernSshAlgorithms() is a pure reshape of modernSshAlgorithmLists() (set-equal per category)', function () {
    $lists = callPrivateStatic(DiskManager::class, 'modernSshAlgorithmLists');
    $shaped = callPrivateStatic(DiskManager::class, 'modernSshAlgorithms');

    assertSameSet($lists['kex'], $shaped['kex'], 'kex');
    assertSameSet($lists['hostkey'], $shaped['hostkey'], 'hostkey');
    assertSameSet($lists['ciphers'], $shaped['client_to_server']['crypt'], 'client_to_server.crypt');
    assertSameSet($lists['macs'], $shaped['client_to_server']['mac'], 'client_to_server.mac');
    assertSameSet($lists['ciphers'], $shaped['server_to_client']['crypt'], 'server_to_client.crypt');
    assertSameSet($lists['macs'], $shaped['server_to_client']['mac'], 'server_to_client.mac');
});

test('F5: modernSshOpensshFlags() carries the SAME algorithm names as modernSshAlgorithmLists() (set-equal)', function () {
    $lists = callPrivateStatic(DiskManager::class, 'modernSshAlgorithmLists');
    $flags = DiskManager::modernSshOpensshFlags();

    assertTrue(count($flags) === 8, 'four -o NAME=values pairs → 8 flat elements');
    $byName = [];
    for ($i = 0; $i < count($flags); $i += 2) {
        assertEqual('-o', $flags[$i], "element {$i} must be the -o switch");
        [$name, $value] = explode('=', $flags[$i + 1], 2);
        $byName[$name] = explode(',', $value);
    }

    assertSameSet($lists['kex'], $byName['KexAlgorithms'] ?? [], 'KexAlgorithms');
    assertSameSet($lists['hostkey'], $byName['HostKeyAlgorithms'] ?? [], 'HostKeyAlgorithms');
    assertSameSet($lists['ciphers'], $byName['Ciphers'] ?? [], 'Ciphers');
    assertSameSet($lists['macs'], $byName['MACs'] ?? [], 'MACs');
});

test('F5: no legacy algorithms leak into either shape (spot-check)', function () {
    $lists = callPrivateStatic(DiskManager::class, 'modernSshAlgorithmLists');
    foreach (['kex' => 'diffie-hellman-group1-sha1', 'hostkey' => 'ssh-dss', 'ciphers' => '3des-cbc', 'macs' => 'hmac-md5'] as $cat => $legacy) {
        assertTrue(!in_array($legacy, $lists[$cat], true), "{$cat} must not contain {$legacy}");
    }
});

// ─────────────────────────────────────────────────────────────────────────
// F6 — LRU eviction is a pure, correct function
// ─────────────────────────────────────────────────────────────────────────

test('F6: under cap → no evictions', function () {
    $cap = 5;
    $index = [];
    for ($i = 0; $i < $cap - 1; $i++) { $index["k{$i}"] = ['last_used' => 1000 + $i]; }
    assertEqual([], SshMultiplexer::selectEvictions($index, $cap));
});

test('F6: exactly at cap (about to add one more → would exceed) → evicts exactly the single oldest entry', function () {
    $cap = 5;
    $index = [];
    for ($i = 0; $i < $cap; $i++) { $index["k{$i}"] = ['last_used' => 1000 + $i]; } // k0 is oldest
    assertEqual(['k0'], SshMultiplexer::selectEvictions($index, $cap));
});

test('F6: several stale entries → evicts oldest-first, in the right order', function () {
    $cap = 3;
    $index = [
        'k_newest' => ['last_used' => 500],
        'k_old'    => ['last_used' => 100],
        'k_mid'    => ['last_used' => 300],
        'k_oldest' => ['last_used' => 50],
        'k_mid2'   => ['last_used' => 400],
    ];
    // count=5, cap=3 → evict count-cap+1=3, ascending by last_used.
    assertEqual(['k_oldest', 'k_old', 'k_mid'], SshMultiplexer::selectEvictions($index, $cap));
});

test('F6: exactly cap-1 entries → still under cap → no evictions', function () {
    assertEqual([], SshMultiplexer::selectEvictions(['a' => ['last_used' => 1], 'b' => ['last_used' => 2]], 3));
});

// ─────────────────────────────────────────────────────────────────────────
// known_hosts line format (piggybacked off phpseclib's own verified connection)
// ─────────────────────────────────────────────────────────────────────────

test('known_hosts: written line is exactly "<host> <keytype> <base64>\\n"', function () {
    $savedEnv = $_ENV['FLUXFILES_STORAGE_PATH'] ?? null;
    $tmp = sys_get_temp_dir() . '/ff-mux-kh-' . bin2hex(random_bytes(4));
    $_ENV['FLUXFILES_STORAGE_PATH'] = $tmp;

    try {
        $fakeConn = new class {
            public function getServerPublicHostKey(): string {
                return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFAKEKEYDATAFORTESTONLY==';
            }
        };
        // DiskManager is not final; SshMultiplexer only calls sftpConnection()
        // through the DiskManager it was handed, so an override here fakes a
        // phpseclib connection that already passed host-key verification —
        // without ever opening a real socket.
        $fakeDm = new class([]) extends DiskManager {
            public $conn;
            public function sftpConnection(string $name): array { return [$this->conn, '']; }
        };
        $fakeDm->conn = $fakeConn;

        $cfg = ['host' => 'example.com', 'port' => 2222, 'host_fingerprint' => 'ab:cd:ef:01'];
        $path = callPrivateStatic(SshMultiplexer::class, 'ensureKnownHosts', [$cfg, $fakeDm, 'sftp']);

        assertTrue($path !== null, 'a known_hosts path is returned');
        assertTrue(is_file($path), 'the file was actually written');
        assertEqual(
            "example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFAKEKEYDATAFORTESTONLY==\n",
            file_get_contents($path)
        );
        assertEqual(0600, fileperms($path) & 0777, 'known_hosts file is 0600');
    } finally {
        if ($savedEnv === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $savedEnv; }
        @exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('known_hosts: no host_fingerprint → TOFU-touches a persistent per-disk file (no phpseclib call needed)', function () {
    $savedEnv = $_ENV['FLUXFILES_STORAGE_PATH'] ?? null;
    $tmp = sys_get_temp_dir() . '/ff-mux-kh-tofu-' . bin2hex(random_bytes(4));
    $_ENV['FLUXFILES_STORAGE_PATH'] = $tmp;

    try {
        // A DiskManager whose sftpConnection() would throw if ever called — proves
        // the no-fingerprint (TOFU) branch never needs a phpseclib round trip.
        $dm = new class([]) extends DiskManager {
            public function sftpConnection(string $name): array {
                throw new \RuntimeException('must not be called when no host_fingerprint is set');
            }
        };
        $cfg = ['host' => 'no-fp.example.com', 'port' => 22];
        $path = callPrivateStatic(SshMultiplexer::class, 'ensureKnownHosts', [$cfg, $dm, 'sftp']);
        assertTrue($path !== null && is_file($path), 'a (empty, TOFU) known_hosts file is created');
        assertEqual('', file_get_contents($path), 'TOFU file starts empty — OpenSSH accept-new pins on first connect, not us');
    } finally {
        if ($savedEnv === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $savedEnv; }
        @exec('rm -rf ' . escapeshellarg($tmp));
    }
});

// ─────────────────────────────────────────────────────────────────────────
// env round-trip (mirrors the SFTP_STRICT_ALGORITHMS/SFTP_REQUIRE_HOST_KEY
// tests already in test-sftp-passphrase.php)
// ─────────────────────────────────────────────────────────────────────────

test('env SFTP_MULTIPLEX=true maps to $disks[sftp][ssh_multiplex]', function () {
    $saved = [$_ENV['SFTP_HOST'] ?? null, $_ENV['SFTP_MULTIPLEX'] ?? null];
    $_ENV['SFTP_HOST'] = '198.51.100.10';
    $_ENV['SFTP_MULTIPLEX'] = 'true';
    try {
        $disks = require __DIR__ . '/../../config/disks.php';
        assertEqual(true, $disks['sftp']['ssh_multiplex'] ?? null);
    } finally {
        [$_ENV['SFTP_HOST'], $_ENV['SFTP_MULTIPLEX']] = $saved;
        foreach (['SFTP_HOST', 'SFTP_MULTIPLEX'] as $i => $k) {
            if ($saved[$i] === null) { unset($_ENV[$k]); }
        }
    }
});

test('ssh_multiplex defaults false (SFTP_MULTIPLEX unset)', function () {
    $saved = [$_ENV['SFTP_HOST'] ?? null, $_ENV['SFTP_MULTIPLEX'] ?? null];
    $_ENV['SFTP_HOST'] = '198.51.100.10';
    unset($_ENV['SFTP_MULTIPLEX']);
    try {
        $disks = require __DIR__ . '/../../config/disks.php';
        assertEqual(false, $disks['sftp']['ssh_multiplex'] ?? null);
    } finally {
        [$_ENV['SFTP_HOST'], $_ENV['SFTP_MULTIPLEX']] = $saved;
        foreach (['SFTP_HOST', 'SFTP_MULTIPLEX'] as $i => $k) {
            if ($saved[$i] === null) { unset($_ENV[$k]); }
        }
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
