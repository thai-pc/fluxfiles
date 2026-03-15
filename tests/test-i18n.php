<?php

/**
 * Test script for FluxFiles i18n — validates all language files.
 *
 * Usage:
 *   php tests/test-i18n.php
 *   php tests/test-i18n.php vi      (test specific locale)
 *   php tests/test-i18n.php --api   (test API endpoints, requires running server)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FluxFiles\I18n;

$langDir  = __DIR__ . '/../lang';
$specific = $argv[1] ?? null;
$testApi  = in_array('--api', $argv, true);

// Colors for terminal
$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles i18n Test Suite                   ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// --- 1. Validate all JSON files ---
echo "{$yellow}[1] Validating JSON files...{$reset}\n";

$enData = json_decode(file_get_contents("{$langDir}/en.json"), true);
$enKeys = flattenKeys($enData);
$files  = glob("{$langDir}/*.json");
$errors = 0;

foreach ($files as $file) {
    $locale  = basename($file, '.json');
    $raw     = file_get_contents($file);
    $data    = json_decode($raw, true);

    if ($data === null) {
        echo "  {$red}✗ {$locale}.json — JSON parse error: " . json_last_error_msg() . "{$reset}\n";
        $errors++;
        continue;
    }

    // Check keys match en.json
    $otherKeys = flattenKeys($data);
    $missing   = array_diff($enKeys, $otherKeys);
    $extra     = array_diff($otherKeys, $enKeys);

    if (!empty($missing)) {
        echo "  {$red}✗ {$locale}.json — Missing " . count($missing) . " keys: " . implode(', ', array_slice($missing, 0, 3)) . "{$reset}\n";
        $errors++;
    } elseif (!empty($extra)) {
        echo "  {$yellow}⚠ {$locale}.json — Extra " . count($extra) . " keys: " . implode(', ', array_slice($extra, 0, 3)) . "{$reset}\n";
    } else {
        $dir = $data['_meta']['direction'] ?? 'ltr';
        $name = $data['_meta']['name'] ?? $locale;
        echo "  {$green}✓ {$locale}.json{$reset} — {$name} ({$dir}) — " . count($otherKeys) . " keys\n";
    }

    // Check placeholders preserved
    foreach ($enKeys as $key) {
        $enVal = resolveKey($enData, $key);
        $trVal = resolveKey($data, $key);

        if ($enVal === null || $trVal === null) continue;

        preg_match_all('/\{(\w+)\}/', $enVal, $enPlaceholders);
        preg_match_all('/\{(\w+)\}/', $trVal, $trPlaceholders);

        $enVars = $enPlaceholders[1];
        $trVars = $trPlaceholders[1];
        sort($enVars);
        sort($trVars);

        if ($enVars !== $trVars) {
            echo "  {$red}  ✗ {$locale}.{$key} — placeholder mismatch! EN: {" . implode('}, {', $enVars) . "} vs {" . implode('}, {', $trVars) . "}{$reset}\n";
            $errors++;
        }
    }
}

// --- 2. Test I18n class for each locale ---
echo "\n{$yellow}[2] Testing I18n class...{$reset}\n";

$locales = $specific && $specific !== '--api'
    ? [$specific]
    : array_map(fn($f) => basename($f, '.json'), $files);

$testKeys = [
    'upload.drop_hint'    => [],
    'file.items'          => ['count' => 42],
    'error.upload_too_large' => ['max' => 10],
    'search.results'      => ['count' => 7, 'query' => 'photo'],
    'delete.confirm_file' => ['name' => 'test.jpg'],
    'trash.auto_delete'   => ['days' => 30],
];

foreach ($locales as $locale) {
    $i18n = new I18n($langDir, $locale);
    $actual = $i18n->locale();

    echo "\n  {$cyan}[{$locale}]{$reset} {$i18n->name()} (dir={$i18n->direction()})\n";

    if ($actual !== $locale) {
        echo "    {$red}✗ Resolved to '{$actual}' instead of '{$locale}'{$reset}\n";
        $errors++;
        continue;
    }

    foreach ($testKeys as $key => $vars) {
        $result = $i18n->t($key, $vars);

        // Translation should not equal the key (means missing)
        if ($result === $key) {
            echo "    {$red}✗ t('{$key}') returned key itself (no translation){$reset}\n";
            $errors++;
        }
        // Variables should be interpolated
        elseif (preg_match('/\{(count|max|name|query|days)\}/', $result)) {
            echo "    {$red}✗ t('{$key}') has unresolved placeholder: {$result}{$reset}\n";
            $errors++;
        } else {
            $preview = mb_strlen($result) > 60 ? mb_substr($result, 0, 57) . '...' : $result;
            echo "    {$green}✓{$reset} {$key} → {$preview}\n";
        }
    }
}

// --- 3. Test API endpoints (optional) ---
if ($testApi) {
    echo "\n{$yellow}[3] Testing API endpoints (http://localhost:8080)...{$reset}\n";

    $baseUrl = 'http://localhost:8080/api/fm';

    // List locales
    $response = @file_get_contents("{$baseUrl}/lang");
    if ($response === false) {
        echo "  {$red}✗ Server not running at localhost:8080{$reset}\n";
        echo "  {$yellow}  Start with: php -S localhost:8080 -t .{$reset}\n";
    } else {
        $json = json_decode($response, true);
        if ($json && isset($json['data'])) {
            echo "  {$green}✓ GET /api/fm/lang{$reset} — " . count($json['data']) . " locales available\n";
            foreach ($json['data'] as $loc) {
                echo "    • {$loc['code']} — {$loc['name']} ({$loc['dir']})\n";
            }
        }

        // Test each locale endpoint
        foreach ($locales as $locale) {
            $resp = @file_get_contents("{$baseUrl}/lang/{$locale}");
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if ($data && isset($data['data']['messages'])) {
                    $msgCount = count(flattenKeys($data['data']['messages']));
                    echo "  {$green}✓ GET /api/fm/lang/{$locale}{$reset} — {$msgCount} keys, dir={$data['data']['dir']}\n";
                }
            } else {
                echo "  {$red}✗ GET /api/fm/lang/{$locale}{$reset} — failed\n";
                $errors++;
            }
        }
    }
}

// --- Summary ---
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
if ($errors === 0) {
    echo "{$green}All tests passed!{$reset} (" . count($files) . " languages, " . count($enKeys) . " keys each)\n";
} else {
    echo "{$red}{$errors} error(s) found.{$reset}\n";
}
echo "\n";

exit($errors > 0 ? 1 : 0);

// --- Helper functions ---

function flattenKeys(array $arr, string $prefix = ''): array
{
    $keys = [];
    foreach ($arr as $k => $v) {
        $full = $prefix !== '' ? "{$prefix}.{$k}" : $k;
        if (is_array($v)) {
            $keys = array_merge($keys, flattenKeys($v, $full));
        } else {
            $keys[] = $full;
        }
    }
    return $keys;
}

function resolveKey(array $arr, string $key): ?string
{
    $parts = explode('.', $key);
    $current = $arr;
    foreach ($parts as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return null;
        }
        $current = $current[$part];
    }
    return is_string($current) ? $current : null;
}
