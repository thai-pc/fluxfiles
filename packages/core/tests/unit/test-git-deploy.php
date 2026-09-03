<?php

/**
 * GitDeploy — the fixed-command-shape assembly + claim wiring. The actual sync
 * needs a live SSH host (covered by manual/e2e); here we lock in the pure,
 * security-relevant bits: escaping/shape of the assembled command, hook
 * neutralization, lock staleness, and that the 4 new claims default OFF/empty
 * and decode correctly (see docs/GIT-DEPLOY-SECURITY-REVIEW.md §4).
 *
 * Usage: php tests/unit/test-git-deploy.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\GitDeploy;
use FluxFiles\Claims;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

echo "\n{$cyan}══ Git deploy: command shape + claim wiring ══{$reset}\n\n";

// No branch → safe ff-only pull, never a forced reset.
test('buildCommand: empty branch produces a ff-only pull, no reset --hard', function () {
    $cmd = GitDeploy::buildCommand('/var/www/site', '', true);
    assertTrue(strpos($cmd, "git -C '/var/www/site' pull --ff-only") !== false, 'contains ff-only pull');
    assertTrue(strpos($cmd, 'reset --hard') === false, 'must NOT reset --hard when no branch is set');
});

// Branch set → the destructive but deterministic fetch+reset form.
test('buildCommand: branch set produces fetch + reset --hard origin/<branch>', function () {
    $cmd = GitDeploy::buildCommand('/var/www/site', 'main', true);
    assertTrue(strpos($cmd, "git -C '/var/www/site' fetch --prune origin") !== false, 'contains fetch --prune');
    assertTrue(strpos($cmd, "git -C '/var/www/site' reset --hard 'origin/main'") !== false, 'contains reset --hard origin/main');
});

// Hooks neutered by default; opt-in flag removes the hooksPath override.
test('buildCommand: hooks neutered by default, opt-in removes the override', function () {
    $off = GitDeploy::buildCommand('/var/www/site', '', false);
    assertTrue(strpos($off, 'core.hooksPath') !== false, 'hooksPath override present when hooks disabled');
    assertTrue(strpos($off, '/dev/null') !== false, 'hooksPath points at /dev/null when disabled');

    $on = GitDeploy::buildCommand('/var/www/site', '', true);
    assertTrue(strpos($on, 'core.hooksPath') === false, 'no hooksPath override when hooks explicitly enabled');
});

// Every variable piece is escapeshellarg()'d — a path/branch with shell metacharacters
// must never break out of its quoted argument.
test('buildCommand: path and branch are shell-escaped, not concatenated raw', function () {
    $cmd = GitDeploy::buildCommand("/var/www/'; rm -rf /; echo '", '', true);
    assertTrue(strpos($cmd, "rm -rf /") === false || strpos($cmd, "\\'") !== false || strpos($cmd, "'\\''") !== false,
        'a single quote in the path must be escaped, not close the shell string early');
    // escapeshellarg wraps in quotes and escapes embedded quotes as '\''
    assertTrue(strpos($cmd, "'\\''") !== false, 'embedded quote is escaped via the standard \'\\\'\' pattern');
});

// The lock directory lives inside the repo path itself (storage-resident state).
test('buildCommand: lock directory is scoped inside the repo path', function () {
    $cmd = GitDeploy::buildCommand('/var/www/site', '', true);
    assertTrue(strpos($cmd, '/var/www/site/.fluxfiles-deploy.lock') !== false, 'lock dir nested under the repo path');
    assertTrue(strpos($cmd, 'mkdir') !== false, 'uses mkdir for atomic lock acquisition');
    assertTrue(strpos($cmd, 'find "$L" -maxdepth 0 -mmin +5') !== false, 'stale-lock check uses portable `find -mmin`, not `stat`');
});

// allow_git_deploy + friends default off/empty and only turn on when explicitly set.
test('allow_git_deploy claim defaults to false, path/branch empty, hooks off', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read', 'write'], 'disks' => ['sftp']]);
    assertTrue($c->allowGitDeploy === false, 'defaults off');
    assertTrue($c->isAllowed('allow_git_deploy') === false, 'isAllowed off by default');
    assertTrue($c->gitDeployPath === '', 'path empty by default');
    assertTrue($c->gitDeployBranch === '', 'branch empty by default');
    assertTrue($c->gitDeployHooks === false, 'hooks off by default');
});

test('allow_git_deploy + path/branch/hooks decode when set', function () {
    $c = Claims::fromJwtPayload((object) [
        'sub' => 'u', 'perms' => ['read', 'write'], 'disks' => ['sftp'],
        'allow_git_deploy' => true,
        'git_deploy_path' => '/var/www/site',
        'git_deploy_branch' => 'release/2.0',
        'git_deploy_hooks' => true,
    ]);
    assertTrue($c->allowGitDeploy === true, 'on when set');
    assertTrue($c->isAllowed('allow_git_deploy') === true, 'isAllowed on');
    assertTrue($c->gitDeployPath === '/var/www/site', 'path decoded');
    assertTrue($c->gitDeployBranch === 'release/2.0', 'branch decoded (allowed charset)');
    assertTrue($c->gitDeployHooks === true, 'hooks decoded true');
});

// git_deploy_branch is restricted to a safe ref charset — a malformed/hostile branch
// claim (however it got minted) is dropped to empty rather than reaching git at all.
test('git_deploy_branch rejects anything outside [A-Za-z0-9._/-]', function () {
    foreach (["main; rm -rf /", 'a`whoami`', '$(id)', 'a && b', "a'b", 'a b', ''] as $bad) {
        $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'git_deploy_branch' => $bad]);
        assertTrue($c->gitDeployBranch === '', "rejected as branch: " . var_export($bad, true));
    }
    foreach (['main', 'release/2.0', 'feature-x', 'v1.2.3', 'a.b_c'] as $ok) {
        $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'git_deploy_branch' => $ok]);
        assertTrue($c->gitDeployBranch === $ok, "kept as branch: {$ok}");
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
