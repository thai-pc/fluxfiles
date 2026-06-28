<?php

/**
 * SshTerminal — the dangerous-command guardrail + claim wiring. The shell runner
 * itself needs a live SSH host (covered by manual/e2e); here we lock in the pure,
 * security-relevant bits: which commands trigger the double-confirm, and that the
 * allow_terminal claim defaults OFF and decodes correctly.
 *
 * Usage: php tests/unit/test-terminal.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\SshTerminal;
use FluxFiles\Claims;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

echo "\n{$cyan}══ SSH terminal: danger guardrail + claim ══{$reset}\n\n";

// Catastrophic commands MUST trigger the confirm.
$dangerous = [
    'rm -rf /',
    'rm -rf /var/www',
    'rm -fr ~',
    'sudo rm -rf --no-preserve-root /',
    'rm -r -f *',
    'dd if=/dev/zero of=/dev/sda',
    'mkfs.ext4 /dev/sdb1',
    ':(){ :|:& };:',
    'shutdown -h now',
    'reboot',
    'chmod -R 777 /',
    'echo x > /dev/sda',
];
test('all catastrophic commands are flagged dangerous', function () use ($dangerous) {
    foreach ($dangerous as $cmd) {
        assertTrue(SshTerminal::isDangerous($cmd), "should flag: {$cmd}");
    }
});

// Everyday commands MUST NOT be flagged (no false positives that nag the user).
$safe = [
    'ls -la',
    'git pull',
    'git status',
    'composer install',
    'npm ci',
    'php artisan migrate',
    'rm cache.txt',
    'rm -f tmp/build.log',
    'rm -rf node_modules',          // common + recoverable; not a catastrophe target
    'tar -czf backup.tgz public/',
    'cat .env.example',
    'chmod 644 index.php',
    'chmod -R 755 storage',
];
test('everyday commands are NOT flagged (no false positives)', function () use ($safe) {
    foreach ($safe as $cmd) {
        assertTrue(!SshTerminal::isDangerous($cmd), "should NOT flag: {$cmd}");
    }
});

// cwd resolves against the SFTP root, not the SSH login home (the "directory not
// found on every command" bug: a relative cwd was run from /root, not /var/www).
test('resolveCwd anchors a relative cwd under the SFTP root', function () {
    assertTrue(SshTerminal::resolveCwd('', '/var/www') === '/var/www',          'empty → root');
    assertTrue(SshTerminal::resolveCwd('html', '/var/www') === '/var/www/html', 'relative → root/relative');
    assertTrue(SshTerminal::resolveCwd('a/b', '/var/www/') === '/var/www/a/b',  'trailing slash on root handled');
    assertTrue(SshTerminal::resolveCwd('/etc', '/var/www') === '/etc',          'absolute → as-is');
    assertTrue(SshTerminal::resolveCwd('', '') === '.',                         'no root, empty → .');
    assertTrue(SshTerminal::resolveCwd('html', '') === 'html',                  'no root, relative → as-is');
});

// allow_terminal defaults OFF and only turns on when explicitly set.
test('allow_terminal claim defaults to false', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read', 'write'], 'disks' => ['sftp']]);
    assertTrue($c->allowTerminal === false, 'defaults off');
    assertTrue($c->isAllowed('allow_terminal') === false, 'isAllowed off by default');
});

test('allow_terminal turns on when set true', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read', 'write'], 'disks' => ['sftp'], 'allow_terminal' => true]);
    assertTrue($c->allowTerminal === true, 'on when set');
    assertTrue($c->isAllowed('allow_terminal') === true, 'isAllowed on');
});

// terminal_pty_url: optional self-hosted PTY server (free, opt-in); http(s) only.
test('terminal_pty_url defaults empty (command-runner mode)', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u']);
    assertTrue($c->terminalPtyUrl === '', 'empty by default → command-runner');
});

test('terminal_pty_url accepts http(s), rejects anything else', function () {
    $ok = Claims::fromJwtPayload((object) ['sub' => 'u', 'terminal_pty_url' => 'https://term.example.com/']);
    assertTrue($ok->terminalPtyUrl === 'https://term.example.com/', 'https kept');
    $http = Claims::fromJwtPayload((object) ['sub' => 'u', 'terminal_pty_url' => 'http://10.0.0.5:7681']);
    assertTrue($http->terminalPtyUrl === 'http://10.0.0.5:7681', 'http kept');
    foreach (['javascript:alert(1)', 'data:text/html,x', 'ws://x', 'ftp://x', 'not a url'] as $bad) {
        $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'terminal_pty_url' => $bad]);
        assertTrue($c->terminalPtyUrl === '', "rejected: {$bad}");
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
