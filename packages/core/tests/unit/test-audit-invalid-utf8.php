<?php

/**
 * Regression: an audit entry whose context carries invalid UTF-8 must still
 * produce a parseable line (free/core security audit 2026-09, finding M-2).
 *
 * AuditLogStorage pipes $_SERVER['HTTP_USER_AGENT'] into the context verbatim,
 * so a single request header used to make json_encode() return false. `false
 * . "\n"` is just "\n", which wrote a blank line — the destructive action
 * still succeeded but became unloggable.
 *
 * Usage:
 *   php tests/unit/test-audit-invalid-utf8.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m";
$red   = "\033[31m";
$cyan  = "\033[36m";
$reset = "\033[0m";

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

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

echo "\n{$cyan}Audit log: invalid UTF-8 in request-controlled context{$reset}\n\n";

$disk = 'audit-disk';
$root = sys_get_temp_dir() . '/ff_test_audit_utf8_' . bin2hex(random_bytes(4));
mkdir($root, 0755, true);
$dm = new DiskManager([$disk => ['driver' => 'local', 'root' => $root]]);
$repo = new StorageMetadataHandler($dm);

// The exact shape AuditLogStorage builds, with a hostile User-Agent.
$badUa = 'A' . chr(0xFF) . 'B';

try {
    test('json_encode without the substitute flag still fails (the bug is real)', function () use ($badUa) {
        $raw = json_encode(['ts' => 1, 'action' => 'delete', 'context' => ['user_agent' => $badUa]]);
        if ($raw !== false) {
            throw new \RuntimeException('expected json_encode() to fail on invalid UTF-8');
        }
    });

    test('audit() writes a parseable line despite invalid UTF-8', function () use ($repo, $disk, $badUa) {
        $repo->audit($disk, 'delete', [
            'user_id' => 'u1',
            'file_key' => 'secret.txt',
            'ip' => '10.0.0.1',
            'user_agent' => $badUa,
        ]);
        $rows = $repo->readAudit($disk);
        if (count($rows) !== 1) {
            throw new \RuntimeException('expected exactly 1 audit row, got ' . count($rows));
        }
        if (($rows[0]['action'] ?? null) !== 'delete') {
            throw new \RuntimeException('action not recorded: ' . json_encode($rows[0]));
        }
        if (($rows[0]['file_key'] ?? null) !== 'secret.txt') {
            throw new \RuntimeException('context lost: ' . json_encode($rows[0]));
        }
        // The hostile byte is substituted, not dropped along with the row.
        if (!is_string($rows[0]['user_agent'] ?? null) || $rows[0]['user_agent'] === '') {
            throw new \RuntimeException('user_agent lost: ' . json_encode($rows[0]));
        }
    });

    test('no blank line is written to the log', function () use ($disk, $root) {
        $raw = (string) file_get_contents($root . '/_fluxfiles/audit.jsonl');
        if (trim($raw) === '') {
            throw new \RuntimeException('audit log is empty');
        }
        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            if (trim($line) === '') {
                throw new \RuntimeException('blank line found in audit.jsonl');
            }
            if (json_decode($line, true) === null) {
                throw new \RuntimeException('unparseable audit line: ' . var_export($line, true));
            }
        }
    });

    test('a second, clean entry still appends normally', function () use ($repo, $disk) {
        $repo->audit($disk, 'upload', ['user_id' => 'u1', 'file_key' => 'ok.txt']);
        $actions = array_column($repo->readAudit($disk), 'action');
        if ($actions !== ['delete', 'upload']) {
            throw new \RuntimeException('unexpected actions: ' . json_encode($actions));
        }
    });
} finally {
    rrmdir($root);
}

echo "\n  Total: " . ($passed + $failed) . ", Passed: {$green}{$passed}{$reset}, Failed: " .
    ($failed > 0 ? "{$red}{$failed}{$reset}" : '0') . "\n\n";

exit($failed > 0 ? 1 : 0);
