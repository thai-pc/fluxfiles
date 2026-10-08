<?php

/**
 * GitDeploy — the fixed-command-shape assembly + claim wiring. The actual sync
 * needs a live SSH host (covered by manual/e2e); here we lock in the pure,
 * security-relevant bits: escaping/shape of the assembled command, hook
 * neutralization, lock staleness, and that the 4 new claims default OFF/empty
 * and decode correctly (see docs/security/GIT-DEPLOY-SECURITY-REVIEW.md §4).
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
    assertTrue((bool) preg_match("#git -C '/var/www/site' .*pull --ff-only#", $cmd), 'contains ff-only pull');
    assertTrue(strpos($cmd, 'reset --hard') === false, 'must NOT reset --hard when no branch is set');
});

// Branch set → the destructive but deterministic fetch+reset form.
test('buildCommand: branch set produces fetch + reset --hard origin/<branch>', function () {
    $cmd = GitDeploy::buildCommand('/var/www/site', 'main', true);
    assertTrue((bool) preg_match("#git -C '/var/www/site' .*fetch --prune origin#", $cmd), 'contains fetch --prune');
    assertTrue((bool) preg_match("#git -C '/var/www/site' .*reset --hard 'origin/main'#", $cmd), 'contains reset --hard origin/main');
});

// Hooks neutered by default (hooksPath -> /dev/null). When explicitly enabled,
// core.hooksPath is NOT left unset — it is pinned to the repo's own real hooks
// dir, so an attacker-set core.hooksPath in .git/config can't redirect it even
// if the config-audit allowlist below ever had a gap (B4, belt-and-suspenders).
test('buildCommand: hooks neutered by default, opt-in pins hooksPath to the real dir instead of leaving it unset', function () {
    $off = GitDeploy::buildCommand('/var/www/site', '', false);
    assertTrue(strpos($off, 'core.hooksPath') !== false, 'hooksPath override present when hooks disabled');
    assertTrue(strpos($off, '/dev/null') !== false, 'hooksPath points at /dev/null when disabled');

    $on = GitDeploy::buildCommand('/var/www/site', '', true);
    assertTrue(strpos($on, 'core.hooksPath') !== false, 'hooksPath is still pinned when hooks are explicitly enabled');
    assertTrue(strpos($on, "core.hooksPath='/dev/null'") === false, 'but NOT to /dev/null — pinned to the repo\'s real hooks dir instead');
    assertTrue(strpos($on, "core.hooksPath='/var/www/site/.git/hooks'") !== false, 'pinned to <path>/.git/hooks');
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

// H-4: hooks are not the only config-driven exec path git offers. core.fsmonitor
// and core.sshCommand are run by `git pull`/`fetch` and come from the repo's OWN
// .git/config — an ordinary extensionless file a write-scoped token can overwrite.
// These overrides are therefore unconditional, unlike core.hooksPath.
test('buildCommand: config-driven exec is neutered even when hooks are enabled', function () {
    foreach ([true, false] as $hooks) {
        $cmd = GitDeploy::buildCommand('/var/www/site', '', $hooks);
        $label = $hooks ? 'hooks on' : 'hooks off';
        assertTrue(strpos($cmd, '-c core.fsmonitor=false') !== false, "fsmonitor disabled ({$label})");
        assertTrue(strpos($cmd, '-c core.sshCommand=ssh') !== false, "sshCommand pinned ({$label})");
        assertTrue(strpos($cmd, '-c protocol.ext.allow=never') !== false, "ext transport refused ({$label})");
        assertTrue(strpos($cmd, '-c protocol.file.allow=never') !== false, "file transport refused ({$label})");
        assertTrue(strpos($cmd, '-c core.askPass=') !== false, "askPass cleared ({$label})");
    }
});

// M-6: `kill -0 -1` returns 0 ("every process you may signal"), so an attacker
// writing "-1" into the lock's pid file would make the lock read as held forever
// and skip the age-based reclaim, wedging every later deploy. The PID must be
// digits-only before kill sees it.
test('buildCommand: the lock PID is validated as digits before kill -0', function () {
    $cmd = GitDeploy::buildCommand('/var/www/site', '', true);
    $guard = 'case "$P" in (*[!0-9]*|"") P="";; esac;';
    assertTrue(strpos($cmd, $guard) !== false,
        'a non-numeric pid is discarded, falling through to the staleness reclaim');
    assertTrue(strpos($cmd, $guard) < strpos($cmd, 'kill -0'),
        'the validation runs before the kill -0 liveness check');
});

// F1(a): the two transports the original $safety listed were a DENY-list, so
// `core.gitProxy` + a `git://` remote still reached an exec. The allowlist form
// (`protocol.allow=never` + explicit https/ssh) is what actually refuses it
// ("fatal: transport 'git' not allowed", before the proxy binary is spawned).
test('buildCommand: transports are an allowlist and core.gitProxy is cleared', function () {
    foreach ([true, false] as $hooks) {
        $cmd = GitDeploy::buildCommand('/var/www/site', '', $hooks);
        $label = $hooks ? 'hooks on' : 'hooks off';
        assertTrue(strpos($cmd, '-c protocol.allow=never') !== false, "all transports denied by default ({$label})");
        assertTrue(strpos($cmd, '-c protocol.https.allow=always') !== false, "https re-allowed ({$label})");
        assertTrue(strpos($cmd, '-c protocol.ssh.allow=always') !== false, "ssh re-allowed ({$label})");
        assertTrue(strpos($cmd, '-c core.gitProxy= ') !== false, "core.gitProxy cleared ({$label})");
        // protocol.allow=never must come BEFORE the per-transport re-allows, or
        // the blanket default would clobber them.
        assertTrue(strpos($cmd, '-c protocol.allow=never') < strpos($cmd, '-c protocol.https.allow=always'),
            "blanket deny precedes the re-allows ({$label})");
    }
});

// F1(b): a smudge filter's name is attacker-chosen (`filter.<anything>.smudge`),
// so no fixed `-c` flag can disable it — the deploy has to AUDIT the repo config
// and refuse instead. The audit must run before any fetch/reset.
//
// B2/B3: the audit uses `--show-scope`, NOT `--local`, because `--local` alone
// misses both `include.path` expansion and the `worktree`-scoped config file —
// see GitDeploy::configAuditCommand()'s docblock. `--show-scope` output is
// filtered to the `local`/`worktree` scopes, never `global`/`system`.
test('buildCommand: a pre-deploy config audit precedes the sync, using --show-scope not --local', function () {
    $cmd = GitDeploy::buildCommand('/var/www/site', 'main', true);
    assertTrue(strpos($cmd, 'config --list --show-scope --name-only') !== false,
        'audits via --show-scope (expands include.path + covers the worktree scope)');
    assertTrue(strpos($cmd, "config --local --list") === false,
        'no longer uses the --local form that both B2 and B3 bypassed');
    assertTrue(strpos($cmd, "grep -E '^(local|worktree)'") !== false,
        'keeps only the local and worktree scope rows');
    assertTrue(strpos($cmd, 'exit 98') !== false, 'aborts with its own exit code');
    $auditAt = strpos($cmd, 'config --list --show-scope --name-only');
    assertTrue($auditAt < strpos($cmd, 'fetch --prune'), 'audit runs before the fetch');
    assertTrue($auditAt < strpos($cmd, 'reset --hard'), 'audit runs before the reset');
    // global/system belong to the operator who set up the VPS, not to anyone
    // who can write a file through FluxFiles, and must never fail a deploy —
    // the scope filter above keeps only local/worktree rows, so a key that is
    // ONLY global/system (never local/worktree) must not be able to trip it.
    assertTrue(strpos($cmd, "'^(global|system)'") === false, 'never filters FOR the global/system scopes');
});

// B4 + allowlist shape: the audit now REJECTS any local/worktree key that is
// NOT on the known-safe set, rather than only rejecting keys someone thought
// to deny. Exercised against the real shell pipeline (grep/cut), since that is
// what runs on the remote host — not just the regex in isolation.
function runConfigAudit(string $repoPath): array
{
    $cmd = \FluxFiles\GitDeploy::buildCommand($repoPath, '', true);
    // Extract just the audit prefix (before the lock scaffolding's mkdir done,
    // i.e. everything up to and including the first "fi; " after UNSAFE_KEY) by
    // running the WHOLE command in a disposable shell — the lock/trap plumbing
    // is idempotent and harmless to run against a throwaway repo dir.
    $tmp = tempnam(sys_get_temp_dir(), 'ffdeploy_');
    file_put_contents($tmp, $cmd);
    $out = shell_exec('sh ' . escapeshellarg($tmp) . ' 2>&1; echo EXIT:$?');
    unlink($tmp);
    $out = (string) $out;
    preg_match('/EXIT:(\d+)\s*$/', $out, $m);
    return ['output' => $out, 'exit' => isset($m[1]) ? (int) $m[1] : -1];
}

function makeTempRepo(): string
{
    $dir = sys_get_temp_dir() . '/ffdeploy_test_' . bin2hex(random_bytes(6));
    mkdir($dir);
    shell_exec('git init -q ' . escapeshellarg($dir));
    shell_exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t.com commit -q --allow-empty -m init');
    return $dir;
}

function rrmdir(string $dir): void
{
    shell_exec('rm -rf ' . escapeshellarg($dir));
}

if (getenv('FFTEST_SKIP_GIT_SHELL') !== '1' && shell_exec('command -v git 2>/dev/null') !== null) {
    // Benign `git init` keys must pass — or every deploy breaks.
    test('config audit: a clean `git init` repo passes', function () {
        $repo = makeTempRepo();
        try {
            $r = runConfigAudit($repo);
            assertTrue($r['exit'] !== 98, "clean repo must not be flagged unsafe: {$r['output']}");
        } finally {
            rrmdir($repo);
        }
    });

    // B2: `include.path` pulling in a second config file with a `filter.*.smudge`
    // key must be caught — this is the exact RCE the reviewer demonstrated.
    test('config audit: include.path bypass is refused (B2)', function () {
        $repo = makeTempRepo();
        $incFile = sys_get_temp_dir() . '/ffdeploy_include_' . bin2hex(random_bytes(6)) . '.cfg';
        file_put_contents($incFile, "[filter \"evil\"]\n\tsmudge = touch /tmp/ffdeploy_test_pwned\n");
        file_put_contents($repo . '/.git/config', "[include]\n\tpath = {$incFile}\n", FILE_APPEND);
        try {
            $r = runConfigAudit($repo);
            assertTrue($r['exit'] === 98, "include.path bypass must be refused, got exit {$r['exit']}: {$r['output']}");
            assertTrue(!file_exists('/tmp/ffdeploy_test_pwned'), 'the smudge filter must never have run');
        } finally {
            @unlink('/tmp/ffdeploy_test_pwned');
            @unlink($incFile);
            rrmdir($repo);
        }
    });

    // B3: `extensions.worktreeConfig=true` + a filter in `.git/config.worktree`
    // must be caught — `--local` never saw either half of this.
    test('config audit: extensions.worktreeConfig + worktree-scoped filter is refused (B3)', function () {
        $repo = makeTempRepo();
        shell_exec('git -C ' . escapeshellarg($repo) . ' config extensions.worktreeConfig true');
        file_put_contents($repo . '/.git/config.worktree', "[filter \"evilwt\"]\n\tsmudge = touch /tmp/ffdeploy_test_pwned_wt\n");
        try {
            $r = runConfigAudit($repo);
            assertTrue($r['exit'] === 98, "worktree-config bypass must be refused, got exit {$r['exit']}: {$r['output']}");
        } finally {
            @unlink('/tmp/ffdeploy_test_pwned_wt');
            rrmdir($repo);
        }
    });

    // B4: core.askPass and core.hooksPath are not on the safe list, so setting
    // either (regardless of the git_deploy_hooks claim) must be refused.
    test('config audit: core.askPass is refused (B4)', function () {
        $repo = makeTempRepo();
        shell_exec('git -C ' . escapeshellarg($repo) . ' config core.askPass /tmp/evil.sh');
        try {
            $r = runConfigAudit($repo);
            assertTrue($r['exit'] === 98, "core.askPass must be refused, got exit {$r['exit']}: {$r['output']}");
            assertTrue(strpos($r['output'], 'core.askpass') !== false, 'the offending key is named in the output');
        } finally {
            rrmdir($repo);
        }
    });

    test('config audit: core.hooksPath set in the repo is refused even with hooks enabled (B4)', function () {
        $repo = makeTempRepo();
        shell_exec('git -C ' . escapeshellarg($repo) . ' config core.hooksPath /tmp/evil-hooks');
        try {
            $r = runConfigAudit($repo);
            assertTrue($r['exit'] === 98, "core.hooksPath must be refused, got exit {$r['exit']}: {$r['output']}");
        } finally {
            rrmdir($repo);
        }
    });

    // Any key outside the allowlist fails closed, including one nobody wrote
    // down — this is the whole point of the allowlist shape (B4).
    test('config audit: an arbitrary unknown key fails closed', function () {
        $repo = makeTempRepo();
        shell_exec('git -C ' . escapeshellarg($repo) . ' config some.totally.unknownkey value');
        try {
            $r = runConfigAudit($repo);
            assertTrue($r['exit'] === 98, "unknown key must fail closed, got exit {$r['exit']}: {$r['output']}");
            assertTrue(strpos($r['output'], 'some.totally.unknownkey') !== false, 'names the offending key for diagnosis');
        } finally {
            rrmdir($repo);
        }
    });
} else {
    echo "  {$cyan}(skipping shell-based config-audit tests — git not available or FFTEST_SKIP_GIT_SHELL=1){$reset}\n";
}

// SAFE_CONFIG_RE exercised directly against the real shell pipeline the audit
// uses (grep -E scope filter + cut -f2 + grep -viE allowlist), not just the
// regex read out of the class — this is what actually runs on the remote host.
test('SAFE_CONFIG_RE: benign git-init/clone keys pass, dangerous keys are rejected', function () {
    $cmd = GitDeploy::buildCommand('/var/www/site', '', true);
    assertTrue((bool) preg_match("~grep -viE '([^']*)'~", $cmd, $m), 'allowlist pattern is present in the command');
    $re = '~' . str_replace('~', '\~', $m[1]) . '~i';

    // Collected from a real `git init` + `git clone` + `git checkout -b` (see
    // the task's empirical check) — these must stay ALLOWED or every ordinary
    // deploy breaks.
    $benign = [
        'core.repositoryformatversion', 'core.filemode', 'core.bare',
        'core.logallrefupdates', 'core.ignorecase', 'core.precomposeunicode',
        'remote.origin.url', 'remote.origin.fetch', 'branch.main.remote', 'branch.main.merge',
        'branch.main.rebase', 'remote.origin.pushurl', 'remote.origin.prune',
        'submodule.active', 'pull.ff', 'user.name', 'user.email',
    ];
    foreach ($benign as $key) {
        // $re is the SAFE allowlist pattern itself (the one passed to `grep -viE`,
        // which prints the keys that DON'T match it) — so a benign key must MATCH.
        assertTrue((bool) preg_match($re, $key), "allowed (matches safe-list): {$key}");
    }

    // Must be rejected: the keys behind B2-B4 plus the classic config-exec set.
    $dangerous = [
        'filter.evil.smudge', 'filter.evil.clean', 'credential.helper',
        'core.gitproxy', 'core.pager', 'core.editor', 'core.sshcommand', 'core.fsmonitor',
        'alias.ci', 'diff.evil.textconv', 'uploadpack.packobjectshook', 'receive.denycurrentbranch',
        'include.path', 'includeif.onbranch:main.path', 'extensions.worktreeconfig',
        'core.askpass', 'core.hookspath',
        // Not on the allowlist, and must stay that way even though it's harmless —
        // this is the allowlist's whole point: unknown == rejected.
        'some.totally.unknownkey',
    ];
    foreach ($dangerous as $key) {
        assertTrue(!preg_match($re, $key), "rejected (does not match safe-list): {$key}");
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
