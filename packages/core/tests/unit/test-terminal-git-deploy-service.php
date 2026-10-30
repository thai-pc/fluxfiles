<?php

/**
 * TerminalService + GitDeployService + Claims::requirePerm/requireDisk: the gate
 * order and error codes of the two SSH routes, without an SSH server (a stub
 * DiskManager stands in for the SFTP disk).
 *
 * Usage: php tests/unit/test-terminal-git-deploy-service.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\GitDeployService;
use FluxFiles\TerminalService;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function expectApi(callable $f, int $http, string $code): void
{
    try { $f(); } catch (ApiException $e) {
        assertEqual($code, (string) $e->getErrorCode(), 'error_code');
        assertEqual($http, $e->getHttpCode(), 'http');
        return;
    }
    throw new \RuntimeException("expected {$code}, nothing thrown");
}

/** Records which connection path the service took. */
class StubSftpDisks extends DiskManager
{
    public array $calls = [];
    public ?array $mux = null;
    public function __construct() { parent::__construct(['sftp' => ['driver' => 'sftp'], 'local' => ['driver' => 'local', 'root' => sys_get_temp_dir()]]); }
    public function multiplexHandle(string $name): ?array { $this->calls[] = 'mux'; return $this->mux; }
    public function sftpConnection(string $name): array { $this->calls[] = 'phpseclib'; throw new \RuntimeException('SENTINEL: phpseclib path'); }
}
class FakeHandle
{
    public array $ran = [];
    public function run(string $cmd, string $cwd, int $timeout): array { $this->ran[] = [$cmd, $cwd, $timeout]; return ['shell_ok' => true, 'stdout' => 'ok', 'cwd' => $cwd]; }
}
function claims(array $perms = ['read', 'write'], array $disks = ['sftp', 'local']): Claims {
    $c = new Claims('u', $perms, $disks, '', 10, null, 0);
    $c->allowTerminal = true;
    $c->allowGitDeploy = true;
    $c->gitDeployPath = '/srv/app';
    return $c;
}

echo "\n{$cyan}══ Claims::requirePerm / requireDisk ══{$reset}\n\n";

test('requirePerm: passes, or 403 permission_denied with core\'s message', function () {
    claims()->requirePerm('write');
    expectApi(fn () => claims(['read'])->requirePerm('write'), 403, 'permission_denied');
    try { claims(['read'])->requirePerm('delete'); } catch (ApiException $e) { assertEqual('Permission denied: delete', $e->getMessage()); }
});

test('requireDisk: passes, or 403 disk_denied naming the disk', function () {
    claims()->requireDisk('sftp');
    expectApi(fn () => claims()->requireDisk('s3'), 403, 'disk_denied');
    try { claims()->requireDisk('s3'); } catch (ApiException $e) { assertEqual('Access denied to disk: s3', $e->getMessage()); }
});

echo "\n{$cyan}══ TerminalService ══{$reset}\n\n";

$env = [];
test('gate order: kill-switch, claim, THEN body is parsed, then perm, disk, driver, cmd', function () use ($env) {
    $dm = new StubSftpDisks();
    $parsed = 0;
    $body = function () use (&$parsed) { $parsed++; return ['disk' => 'sftp', 'cmd' => 'ls']; };
    expectApi(fn () => TerminalService::run(claims(), $dm, $body, ['FLUXFILES_TERMINAL_DISABLED' => 'true']), 403, 'terminal_disabled');
    $c = claims(); $c->allowTerminal = false;
    expectApi(fn () => TerminalService::run($c, $dm, $body, []), 403, 'terminal_forbidden');
    assertEqual(0, $parsed, 'the body must not be parsed before the 403 gates');
    expectApi(fn () => TerminalService::run(claims(['read']), $dm, $body, []), 403, 'permission_denied');
    assertEqual(1, $parsed);
    expectApi(fn () => TerminalService::run(claims(), $dm, ['disk' => 'nope', 'cmd' => 'ls'], []), 403, 'disk_denied');
    expectApi(fn () => TerminalService::run(claims(), $dm, ['disk' => 'local', 'cmd' => 'ls'], []), 400, 'terminal_unsupported');
    expectApi(fn () => TerminalService::run(claims(), $dm, ['disk' => 'sftp', 'cmd' => '  '], []), 400, 'missing_param');
    assertEqual([], $dm->calls, 'no connection opened by a refused request');
});

test('a data array is never treated as a callable (only a Closure is lazy)', function () {
    $dm = new StubSftpDisks();
    // ['FluxFiles\\TerminalService','assertEnabled'] is a valid PHP callable array; as DATA it must stay data.
    expectApi(fn () => TerminalService::run(claims(), $dm, ['FluxFiles\\TerminalService', 'assertEnabled'], []), 403, 'disk_denied');
});

test('dangerous command needs confirm (409) unless FLUXFILES_TERMINAL_CONFIRM=false', function () {
    $dm = new StubSftpDisks();
    $dm->mux = [new FakeHandle(), '/home/u'];
    $b = ['disk' => 'sftp', 'cmd' => 'rm -rf /'];
    expectApi(fn () => TerminalService::run(claims(), $dm, $b, []), 409, 'terminal_confirm_required');
    $r = TerminalService::run(claims(), $dm, $b + ['confirm' => true], []);
    assertEqual(true, $r['shell_ok']);
    $r = TerminalService::run(claims(), $dm, $b, ['FLUXFILES_TERMINAL_CONFIRM' => 'false']);
    assertEqual(true, $r['shell_ok']);
});

test('ControlMaster handle is used when available; cwd is anchored to the SFTP root; timeout from env', function () {
    $dm = new StubSftpDisks();
    $h = new FakeHandle();
    $dm->mux = [$h, '/home/u/www'];
    $r = TerminalService::run(claims(), $dm, ['disk' => 'sftp', 'cmd' => 'ls', 'cwd' => 'html'], ['FLUXFILES_TERMINAL_TIMEOUT' => '7']);
    assertEqual(['mux'], $dm->calls);
    assertEqual('ls', $h->ran[0][0]);
    assertTrue(str_starts_with($h->ran[0][1], '/home/u/www'), $h->ran[0][1]);
    assertEqual(7, $h->ran[0][2]);
    assertEqual('ok', $r['stdout']);
});

test('FLUXFILES_SSH_MULTIPLEX_DISABLED=true skips the handle (falls back to the phpseclib path); no handle also falls back', function () {
    $dm = new StubSftpDisks();
    $dm->mux = [new FakeHandle(), '/r'];
    try { TerminalService::run(claims(), $dm, ['disk' => 'sftp', 'cmd' => 'ls'], ['FLUXFILES_SSH_MULTIPLEX_DISABLED' => 'true']); }
    catch (\RuntimeException $e) { assertTrue(str_contains($e->getMessage(), 'SENTINEL')); }
    assertEqual(['phpseclib'], $dm->calls, 'multiplexHandle() must not even be asked');
    $dm = new StubSftpDisks();
    try { TerminalService::run(claims(), $dm, ['disk' => 'sftp', 'cmd' => 'ls'], []); }
    catch (\RuntimeException $e) { assertTrue(str_contains($e->getMessage(), 'SENTINEL')); }
    assertEqual(['mux', 'phpseclib'], $dm->calls);
});

test('a host that forces a command (no cwd marker) -> 400 terminal_no_shell', function () {
    $dm = new StubSftpDisks();
    $dm->mux = [new class { public function run($c, $w, $t): array { return ['shell_ok' => false]; } }, '/r'];
    expectApi(fn () => TerminalService::run(claims(), $dm, ['disk' => 'sftp', 'cmd' => 'ls'], []), 400, 'terminal_no_shell');
});

echo "\n{$cyan}══ GitDeployService ══{$reset}\n\n";

test('gate order: kill-switch, claim, perm, THEN body, disk, driver, path claim', function () {
    $dm = new StubSftpDisks();
    $parsed = 0;
    $body = function () use (&$parsed) { $parsed++; return ['disk' => 'sftp']; };
    expectApi(fn () => GitDeployService::run(claims(), $dm, $body, ['FLUXFILES_GIT_DEPLOY_DISABLED' => 'true']), 403, 'git_deploy_disabled');
    $c = claims(); $c->allowGitDeploy = false;
    expectApi(fn () => GitDeployService::run($c, $dm, $body, []), 403, 'git_deploy_forbidden');
    expectApi(fn () => GitDeployService::run(claims(['read']), $dm, $body, []), 403, 'permission_denied');
    assertEqual(0, $parsed, 'body parsed only after the perm gate');
    expectApi(fn () => GitDeployService::run(claims(), $dm, ['disk' => 'nope'], []), 403, 'disk_denied');
    expectApi(fn () => GitDeployService::run(claims(), $dm, ['disk' => 'local'], []), 400, 'terminal_unsupported');
    $noPath = claims(); $noPath->gitDeployPath = '';
    expectApi(fn () => GitDeployService::run($noPath, $dm, ['disk' => 'sftp'], []), 400, 'git_deploy_unconfigured');
    assertEqual([], $dm->calls);
});

test('the repo path comes from the claim, never the body: a hostile body path is ignored', function () {
    $dm = new StubSftpDisks();
    try { GitDeployService::run(claims(), $dm, ['disk' => 'sftp', 'path' => '/etc', 'branch' => 'evil'], []); }
    catch (\RuntimeException $e) { assertTrue(str_contains($e->getMessage(), 'SENTINEL')); }
    assertEqual(['phpseclib'], $dm->calls, 'reached the connect step using only claims');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
