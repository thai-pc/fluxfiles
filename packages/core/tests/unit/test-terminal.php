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

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
