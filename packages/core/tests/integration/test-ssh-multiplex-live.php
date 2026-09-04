<?php

/**
 * Live SSH ControlMaster (connection-reuse) round-trip — env-gated, skips
 * cleanly when no shell-capable SSH host is configured. Mirrors
 * tests/e2e/test-sftp-live.php's env-gating pattern.
 *
 * This feature needs a REAL interactive-capable shell account (not an
 * SFTP-chroot-only server like the `atmoz/sftp` container CI already boots for
 * test-sftp-live.php — see docs/SFTP-CONTROLMASTER-SPEC.md §19, this is
 * SshTerminal-only), so this test is NOT expected to run in CI by default —
 * only when a developer points it at a real box with the `ssh` binary and a
 * PASSPHRASE-LESS private key (multiplexing is key-auth-only, §7).
 *
 * Required env (skips if FXTEST_SSHMUX_HOST or FXTEST_SSHMUX_PRIVATE_KEY is
 * empty/missing):
 *   FXTEST_SSHMUX_HOST           ssh host
 *   FXTEST_SSHMUX_PORT           port (default 22)
 *   FXTEST_SSHMUX_USERNAME       username
 *   FXTEST_SSHMUX_PRIVATE_KEY    path to a PASSPHRASE-LESS private key file
 *   FXTEST_SSHMUX_PASSWORD       optional — also exercises the password-disk fallback sub-test
 *   FXTEST_SSHMUX_CWD            optional remote cwd for the test commands (default '.')
 *   FXTEST_SSHMUX_ALLOW_HOST     set to bypass the SSRF guard for a 127.0.0.1 test box
 *                                (only needed for the phpseclib fallback sub-test — the
 *                                real `ssh` binary path never consults SsrfGuard)
 *
 * Example (a local sshd on 127.0.0.1:2299 with key-based login):
 *   FXTEST_SSHMUX_HOST=127.0.0.1 FXTEST_SSHMUX_PORT=2299 FXTEST_SSHMUX_USERNAME=ff \
 *   FXTEST_SSHMUX_PRIVATE_KEY=/path/to/id_ed25519 FXTEST_SSHMUX_ALLOW_HOST=1 \
 *   php tests/integration/test-ssh-multiplex-live.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\SshMultiplexer;
use FluxFiles\SshTerminal;
use FluxFiles\SsrfGuard;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

$host = getenv('FXTEST_SSHMUX_HOST') ?: '';
if ($host === '') {
    echo "{$yellow}SKIP{$reset} test-ssh-multiplex-live.php — set FXTEST_SSHMUX_HOST (+ FXTEST_SSHMUX_PRIVATE_KEY, a passphrase-less key) to run the live ControlMaster round-trip.\n";
    exit(0);
}

$keyPath = getenv('FXTEST_SSHMUX_PRIVATE_KEY') ?: '';
if ($keyPath === '' || !is_file($keyPath)) {
    echo "{$yellow}SKIP{$reset} test-ssh-multiplex-live.php — FXTEST_SSHMUX_PRIVATE_KEY must point to a real, passphrase-less private key file (multiplexing is key-auth-only, spec §7).\n";
    exit(0);
}

@exec('command -v ssh', $sshLookup, $sshLookupCode);
if ($sshLookupCode !== 0) {
    echo "{$yellow}SKIP{$reset} test-ssh-multiplex-live.php — no `ssh` binary available on this machine.\n";
    exit(0);
}

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

// Local test boxes (127.0.0.1) are blocked by the SSRF guard; opt in explicitly
// — only the phpseclib fallback sub-test below goes through DiskManager's
// buildSftpProvider()/SsrfGuard path. SshMultiplexer's own cold connect shells
// out directly to the real `ssh` binary and never consults SsrfGuard at all
// (it's driven from an already-configured, already-trusted disk).
if (getenv('FXTEST_SSHMUX_ALLOW_HOST')) {
    SsrfGuard::$allowTestHosts[] = strtolower($host);
}

$port = (int) (getenv('FXTEST_SSHMUX_PORT') ?: 22);
$username = getenv('FXTEST_SSHMUX_USERNAME') ?: '';
$privateKey = (string) file_get_contents($keyPath);
$cwd = getenv('FXTEST_SSHMUX_CWD') ?: '.';

// A fresh, dedicated FLUXFILES_STORAGE_PATH for this run so ssh-sockets/ starts
// empty and every assertion below is about exactly what this test created.
// Deliberately anchored at /tmp (not sys_get_temp_dir(), which on macOS resolves
// to a long per-user path under /var/folders/... that alone can blow the
// socket's 100-byte sun_path budget checked in SshMultiplexer — that's a REAL
// guard this test must not accidentally trip on itself).
$storagePath = '/tmp/ffmux-live-' . bin2hex(random_bytes(4));
$_ENV['FLUXFILES_STORAGE_PATH'] = $storagePath;
// Minimum allowed ControlPersist — SshMultiplexer::persistSeconds() clamps to
// [10,120], so this (not a smaller value) is the fastest window we can actually
// make expire in this test.
$_ENV['FLUXFILES_SSH_MULTIPLEX_PERSIST'] = '10';

$socketDir = $storagePath . '/ssh-sockets';

function muxCacheKey(array $cfg): string {
    $m = new \ReflectionMethod(SshMultiplexer::class, 'cacheKey');
    $m->setAccessible(true);
    return $m->invoke(null, $cfg);
}
function muxSocketPath(string $hash, string $dir): string {
    $m = new \ReflectionMethod(SshMultiplexer::class, 'socketPath');
    $m->setAccessible(true);
    return $m->invoke(null, $hash, $dir);
}

/**
 * OpenSSH's ControlMaster forks/detaches into the background ControlPersist
 * daemon slightly AFTER our proc_open() sees the foreground `ssh` process exit
 * (that's what execCold() waits on) — a genuine, observed OS-level fork race,
 * not a FluxFiles bug. A single immediate file_exists() right after run()
 * returns can therefore flake; poll briefly instead.
 *
 * Note: this checks socket paths, so it deliberately uses file_exists()
 * rather than is_file() — is_file() requires S_ISREG and always returns
 * false for a Unix domain socket special file, which would make every
 * "socket created" assertion below a permanent false negative (and every
 * negated "socket gone" assertion a permanent, bug-hiding false positive).
 */
function waitForFile(string $path, float $timeoutSec = 2.0): bool {
    $start = microtime(true);
    while (microtime(true) - $start < $timeoutSec) {
        if (file_exists($path)) { return true; }
        usleep(20_000);
    }
    return file_exists($path);
}

echo "\n{$cyan}══ Live SSH ControlMaster round-trip ({$host}:{$port}) ══{$reset}\n\n";

$dm = new DiskManager([]); // SshMultiplexer::acquire() is driven directly here, mirroring the already-eligibility-checked call site in DiskManager::multiplexHandle()
$cfg = [
    'driver' => 'sftp',
    'host' => $host,
    'port' => $port,
    'username' => $username,
    'private_key' => $privateKey,
    'private_key_passphrase' => '',
    'ssh_multiplex' => true,
];
$expectedHash = muxCacheKey($cfg);
$expectedSocket = muxSocketPath($expectedHash, $socketDir);

$mux = SshMultiplexer::acquire($cfg, 'sshmux-live', $dm);

$firstInode = null;
$t1 = 0.0; $t2 = 0.0;

test('cold connect: first command runs and registers a tracked socket at the expected cache-key path', function () use ($mux, $cwd, &$firstInode, &$t1, $expectedSocket) {
    $start = microtime(true);
    $r = $mux->run('echo fluxfiles-one', $cwd, 15);
    $t1 = microtime(true) - $start;
    assertTrue($r['shell_ok'], 'shell_ok on cold connect');
    assertEqual(0, $r['exit'], 'exit 0');
    assertTrue(strpos($r['output'], 'fluxfiles-one') !== false, 'output contains "fluxfiles-one"');
    assertTrue(waitForFile($expectedSocket), 'socket file created at the expected cache-key path');
    $firstInode = stat($expectedSocket)['ino'];
});

test('reuse: an immediate second command reuses the same socket (unchanged inode)', function () use ($mux, $cwd, &$firstInode, &$t2, $expectedSocket) {
    $start = microtime(true);
    $r = $mux->run('echo fluxfiles-two', $cwd, 15);
    $t2 = microtime(true) - $start;
    assertTrue($r['shell_ok'], 'shell_ok on reuse');
    assertTrue(strpos($r['output'], 'fluxfiles-two') !== false, 'output contains "fluxfiles-two"');
    assertTrue(file_exists($expectedSocket), 'socket file still present');
    assertEqual($firstInode, stat($expectedSocket)['ino'], 'same inode → the existing master was reused, not recreated');
});

test('cold vs. reuse timing sanity: reuse is not meaningfully slower than the cold connect', function () use (&$t1, &$t2) {
    assertTrue($t2 <= $t1 + 0.25, "reuse ({$t2}s) should not be slower than cold connect ({$t1}s) — a regression here means reuse silently isn't happening");
});

test('ControlPersist expiry: after the persist window, the master self-expires and the next command cold-reconnects', function () use ($mux, $cwd, &$firstInode, $expectedSocket) {
    sleep(13); // FLUXFILES_SSH_MULTIPLEX_PERSIST=10 (clamp floor) + margin for OpenSSH's own self-exit
    assertTrue(!file_exists($expectedSocket), 'the expired master unlinked its own control socket');

    $r = $mux->run('echo fluxfiles-three', $cwd, 15);
    assertTrue($r['shell_ok'], 'shell_ok on the post-expiry cold reconnect');
    assertTrue(strpos($r['output'], 'fluxfiles-three') !== false, 'output contains "fluxfiles-three"');
    assertTrue(waitForFile($expectedSocket), 'a fresh socket file exists after cold-reconnecting');
    assertTrue(stat($expectedSocket)['ino'] !== $firstInode, 'new inode → this is a genuinely new master process, not the expired one lingering');
});

test('explicit teardown: evict() removes the tracker entry and unlinks the socket', function () use ($expectedHash, $expectedSocket) {
    SshMultiplexer::evict($expectedHash);
    assertTrue(!file_exists($expectedSocket), 'socket file removed after explicit eviction');
});

$password = getenv('FXTEST_SSHMUX_PASSWORD') ?: '';
if ($password === '') {
    echo "  {$yellow}SKIP{$reset} password-disk end-to-end fallback — set FXTEST_SSHMUX_PASSWORD to also cover it\n";
} else {
    test('password-disk fallback: ssh_multiplex=true + password-only still runs via phpseclib, and never touches ssh-sockets/', function () use ($host, $port, $username, $password, $cwd, $socketDir) {
        $pwCfg = [
            'driver' => 'sftp', 'host' => $host, 'port' => $port, 'username' => $username,
            'password' => $password, 'ssh_multiplex' => true, // ineligible (no private_key) → must fall back
        ];
        $liveDm = new DiskManager(['pwdisk' => $pwCfg]);
        assertTrue($liveDm->multiplexHandle('pwdisk') === null, 'multiplexHandle() refuses a password-only disk regardless of ssh_multiplex');

        [$conn, $connRoot] = $liveDm->sftpConnection('pwdisk');
        $r = SshTerminal::run($conn, 'echo fluxfiles-via-phpseclib', $cwd, 15);
        assertTrue($r['shell_ok'], 'phpseclib fallback shell_ok');
        assertTrue(strpos($r['output'], 'fluxfiles-via-phpseclib') !== false, 'command actually ran over the plain phpseclib path');

        $socks = is_dir($socketDir) ? (glob($socketDir . '/*.sock') ?: []) : [];
        assertEqual([], $socks, 'no socket file was ever created for the password-only disk');
    });
}

// Best-effort cleanup: tear down anything left + the temp storage tree.
SshMultiplexer::evict($expectedHash);
@exec('rm -rf ' . escapeshellarg($storagePath));

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
