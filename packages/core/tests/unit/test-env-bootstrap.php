<?php

/**
 * EnvBootstrap: real env vars reach $_ENV, and win over .env.
 *
 * Core reads its config from $_ENV. Under the production `variables_order =
 * GPCS` PHP leaves $_ENV empty, and Dotenv's immutable loader then also skipped
 * the .env value for a key that was in the real environment (it saw it in
 * $_SERVER), so the setting was lost outright. Each scenario runs in a child
 * PHP process with a controlled environment and `-d variables_order=GPCS`.
 *
 * Usage: php tests/unit/test-env-bootstrap.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\EnvBootstrap;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertSame($expected, $actual, string $m): void {
    if ($expected !== $actual) {
        throw new \RuntimeException($m . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$core = realpath(__DIR__ . '/../..');

/** A temp dir holding a .env with $lines (or no .env when null). */
function envDir(?string $lines): string {
    $dir = sys_get_temp_dir() . '/fluxfiles-envboot-' . uniqid();
    mkdir($dir);
    if ($lines !== null) {
        file_put_contents($dir . '/.env', $lines);
    }
    return $dir;
}

/**
 * Run $code in a child PHP with exactly $env (plus PATH) and GPCS; returns the
 * JSON it prints.
 *
 * @param array<string,string> $env
 */
function child(string $code, array $env): array {
    global $core;
    $env += ['PATH' => (string) getenv('PATH')];
    $script = "<?php require " . var_export($core . '/vendor/autoload.php', true) . ";\n" . $code;
    $file = sys_get_temp_dir() . '/fluxfiles-envboot-' . uniqid() . '.php';
    file_put_contents($file, $script);
    $proc = proc_open([PHP_BINARY, '-d', 'variables_order=GPCS', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    @unlink($file);
    $json = json_decode((string) $out, true);
    if (!is_array($json)) {
        throw new \RuntimeException('child printed no JSON: ' . trim($out . ' ' . $err));
    }
    return $json;
}

$dump = 'echo json_encode(["a" => $_ENV["FLUXFILES_PROBE_A"] ?? null, "b" => $_ENV["FLUXFILES_PROBE_B"] ?? null,'
    . ' "aws" => $_ENV["AWS_BUCKET"] ?? null, "other" => $_ENV["UNRELATED_SECRET"] ?? null]);';

echo "\n{$cyan}══ EnvBootstrap ══{$reset}\n\n";

test('the old bootstrap lost a key set in both the real env and .env (the bug this fixes)', function () use ($dump) {
    $dir = envDir("FLUXFILES_PROBE_A=from-dotenv\n");
    $r = child('Dotenv\Dotenv::createImmutable(' . var_export($dir, true) . ')->safeLoad();' . $dump, ['FLUXFILES_PROBE_A' => 'from-real-env']);
    assertSame(null, $r['a'], 'plain Dotenv under GPCS');
});

test('a real env var wins over .env, and .env fills keys the env does not set', function () use ($dump) {
    $dir = envDir("FLUXFILES_PROBE_A=from-dotenv\nFLUXFILES_PROBE_B=only-in-dotenv\n");
    $r = child('FluxFiles\EnvBootstrap::load([' . var_export($dir, true) . ']);' . $dump, ['FLUXFILES_PROBE_A' => 'from-real-env']);
    assertSame('from-real-env', $r['a'], 'real env var');
    assertSame('only-in-dotenv', $r['b'], '.env-only key');
});

test('with no .env at all, real env vars still reach $_ENV', function () use ($dump) {
    $dir = envDir(null);
    $r = child('$loaded = FluxFiles\EnvBootstrap::load([' . var_export($dir, true) . ']); if ($loaded !== null) { exit(1); }' . $dump, [
        'FLUXFILES_PROBE_A' => 'real', 'AWS_BUCKET' => 'my-bucket',
    ]);
    assertSame('real', $r['a'], 'FLUXFILES_ key');
    assertSame('my-bucket', $r['aws'], 'AWS_ key');
});

test('only the known prefixes are copied, never the whole process environment', function () use ($dump) {
    $r = child('FluxFiles\EnvBootstrap::load([]);' . $dump, ['UNRELATED_SECRET' => 'db-password', 'FLUXFILES_PROBE_A' => 'x']);
    assertSame(null, $r['other'], 'non-prefixed key');
    assertSame('x', $r['a'], 'prefixed key');
});

test('importProcessEnv() keeps a key $_ENV already has and counts what it filled', function () {
    $saved = $_ENV;
    try {
        $_ENV = ['FLUXFILES_KEEP' => 'loaded-first'];
        $n = EnvBootstrap::importProcessEnv([
            'FLUXFILES_KEEP' => 'from-env', 'FLUXFILES_NEW' => 'v', 'R2_BUCKET' => 'b', 'SFTP_HOST' => 'h',
            'PATH' => '/bin', 'FLUXFILES_EMPTY' => '',
        ]);
        assertSame(3, $n, 'filled count');
        assertSame('loaded-first', $_ENV['FLUXFILES_KEEP'], 'existing key kept');
        assertSame('v', $_ENV['FLUXFILES_NEW'], 'new key');
        assertSame(false, isset($_ENV['PATH']), 'PATH not copied');
        assertSame(false, isset($_ENV['FLUXFILES_EMPTY']), 'empty value not copied');
    } finally {
        $_ENV = $saved;
    }
});

test('embed.php mints with FLUXFILES_SECRET from the real env when there is no .env', function () use ($core) {
    $code = 'require ' . var_export($core . '/embed.php', true) . ';'
        . 'echo json_encode(["t" => fluxfiles_token(["user" => "u1", "perms" => ["read"]])]);';
    $r = child($code, ['FLUXFILES_SECRET' => str_repeat('s', 40)]);
    assertSame(3, count(explode('.', (string) $r['t'])), 'a JWT');
});

test('every entry point loads config through EnvBootstrap (no raw Dotenv left)', function () use ($core) {
    $files = array_merge([$core . '/api/index.php', $core . '/bin/fluxfiles'], glob($core . '/scripts/*.php') ?: []);
    foreach ($files as $f) {
        assertSame(0, preg_match('/Dotenv::create/', (string) file_get_contents($f)), basename($f) . ' still calls Dotenv directly');
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n\n";
exit($failed > 0 ? 1 : 0);
