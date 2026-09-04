<?php

/**
 * GitDeploy lock liveness check (regression for the F6-defeating race: a
 * purely time-based staleness check let a second trigger `rm -rf` + steal a
 * lock that was still held by a genuinely running deploy, because
 * FLUXFILES_GIT_DEPLOY_TIMEOUT is routinely raised past LOCK_STALE_MINUTES
 * for slow LFS/submodule fetches). See docs/GIT-DEPLOY-SECURITY-REVIEW.md §F6
 * and GitDeploy::buildCommand()'s docblock for the fix.
 *
 * GitDeploy::buildCommand() returns a plain POSIX shell script (SSH2::exec()
 * just hands it to the remote shell) — there is nothing SSH-specific about
 * the lock-guard portion, so this test runs the exact generated script
 * locally against a throwaway temp directory, manipulating the lock
 * directory / pid file / mtime directly instead of waiting out real
 * 5-minute windows or spinning up an SSH server.
 *
 * Usage: php tests/integration/test-git-deploy-lock.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\GitDeploy;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

const LOCKED_MARK = '__ffdeploy_locked__';
// A PID that will not exist on any sane machine (Linux pid_max default is
// 4194304, macOS's is far lower still) — used to simulate a dead owner
// without the flakiness of spawning and reaping a real process.
const DEAD_PID = 999999;
const STALE_TIMESTAMP = '202001010000'; // touch -t format, far past any 5-min window

/** Fresh repo dir (not a real git repo — we only need it past the lock gate). */
function makeRepoDir(): string {
    $dir = sys_get_temp_dir() . '/ff-gitdeploy-lock-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    return $dir;
}

function lockDirOf(string $repo): string {
    return $repo . '/.fluxfiles-deploy.lock';
}

/**
 * Run GitDeploy::buildCommand()'s script against a local temp dir via a real
 * shell (proc_open, array command — no extra shell-quoting layer), mirroring
 * how SSH2::exec() hands the string straight to the remote shell.
 *
 * @return array{output:string,exit:int}
 */
function runLockScript(string $repo): array {
    $script = GitDeploy::buildCommand($repo, '', true);
    $p = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $out .= stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($p);
    return ['output' => (string) $out, 'exit' => $exit];
}

function seedLock(string $repo, ?string $pid, bool $stale): void {
    $lock = lockDirOf($repo);
    mkdir($lock, 0777, true);
    if ($pid !== null) {
        file_put_contents($lock . '/pid', $pid . "\n");
    }
    if ($stale) {
        // Push both mtime AND atime into the past — `find -mmin` reads mtime,
        // but set both for a clean, unambiguous fixture.
        exec('touch -t ' . escapeshellarg(STALE_TIMESTAMP) . ' ' . escapeshellarg($lock));
    }
}

echo "\n{$cyan}══ GitDeploy: lock is liveness-checked, not just time-checked (F6) ══{$reset}\n\n";

// The actual bug: a lock held by a PID that is confirmed ALIVE must be
// refused outright, even when the lock directory looks old enough to be
// "stale" by the old mtime-only rule. This is the exact race a slow
// LFS/submodule deploy (running past LOCK_STALE_MINUTES) used to lose.
test('alive owner + old mtime → refused, lock left untouched (the F6 regression)', function () {
    $repo = makeRepoDir();
    $lock = lockDirOf($repo);
    // getmypid() is this very PHP process — guaranteed alive for the
    // duration of the test, so `kill -0` on it always succeeds.
    seedLock($repo, (string) getmypid(), true);

    $r = runLockScript($repo);

    assertTrue(strpos($r['output'], LOCKED_MARK) !== false, 'must print the locked marker');
    assertTrue($r['exit'] === 99, 'must exit 99 (locked), got ' . $r['exit']);
    assertTrue(is_dir($lock), 'lock directory must NOT be removed while its owner is alive');
    assertTrue(trim((string) file_get_contents($lock . '/pid')) === (string) getmypid(), 'pid file must be untouched (not overwritten by a stolen lock)');
});

// Crash-recovery case the staleness check exists for: a dead owner's stale
// lock IS reclaimed and the deploy proceeds (past the lock gate — it will
// still fail below on "not a git repository", which just proves it got that
// far and wasn't blocked at 99).
test('dead owner + old mtime → reclaimed, deploy proceeds', function () {
    $repo = makeRepoDir();
    $lock = lockDirOf($repo);
    seedLock($repo, (string) DEAD_PID, true);

    $r = runLockScript($repo);

    assertTrue(strpos($r['output'], LOCKED_MARK) === false, 'must NOT print the locked marker');
    assertTrue($r['exit'] !== 99, 'must not exit with the locked code, got ' . $r['exit']);
    assertTrue(!is_dir($lock), 'the self-owned lock must be cleaned up by the EXIT trap after the (failed) sync');
});

// Age is now a secondary sanity check, not the sole signal: a dead owner
// whose lock is still FRESH must not be reclaimed just because the PID
// happens to be dead (defends against a narrow PID-reuse race and matches
// the documented "secondary, not primary" contract).
test('dead owner + fresh mtime → still refused (age is a secondary check)', function () {
    $repo = makeRepoDir();
    seedLock($repo, (string) DEAD_PID, false);

    $r = runLockScript($repo);

    assertTrue(strpos($r['output'], LOCKED_MARK) !== false, 'must print the locked marker');
    assertTrue($r['exit'] === 99, 'must exit 99 (locked), got ' . $r['exit']);
});

// Backward compatibility: a lock left by a pre-liveness-check version of this
// script has no pid file at all. That must fall back to the old mtime-only
// behavior exactly (fresh → refused, stale → reclaimed) rather than treating
// a missing pid file as "definitely dead" or "definitely alive".
test('no pid file (legacy lock shape) + fresh mtime → refused', function () {
    $repo = makeRepoDir();
    seedLock($repo, null, false);

    $r = runLockScript($repo);

    assertTrue(strpos($r['output'], LOCKED_MARK) !== false, 'must print the locked marker');
    assertTrue($r['exit'] === 99, 'must exit 99 (locked), got ' . $r['exit']);
});

test('no pid file (legacy lock shape) + old mtime → reclaimed', function () {
    $repo = makeRepoDir();
    $lock = lockDirOf($repo);
    seedLock($repo, null, true);

    $r = runLockScript($repo);

    assertTrue(strpos($r['output'], LOCKED_MARK) === false, 'must NOT print the locked marker');
    assertTrue($r['exit'] !== 99, 'must not exit with the locked code, got ' . $r['exit']);
    assertTrue(!is_dir($lock), 'the self-owned lock must be cleaned up by the EXIT trap after the (failed) sync');
});

// No lock at all: the common case, must proceed straight through and clean
// up its own freshly-created lock afterward.
test('no existing lock → proceeds immediately, self-cleans afterward', function () {
    $repo = makeRepoDir();
    $lock = lockDirOf($repo);

    $r = runLockScript($repo);

    assertTrue(strpos($r['output'], LOCKED_MARK) === false, 'must NOT print the locked marker');
    assertTrue($r['exit'] !== 99, 'must not exit with the locked code, got ' . $r['exit']);
    assertTrue(!is_dir($lock), 'the self-owned lock must be cleaned up by the EXIT trap');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
