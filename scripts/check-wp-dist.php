<?php

declare(strict_types=1);

/**
 * Guard for the free WordPress.org plugin zip (docs/design/WPORG-SPLIT-DESIGN.md §8.2).
 *
 *   bash scripts/build-wordpress.sh [X.Y.Z]
 *   php  scripts/check-wp-dist.php [path/to/fluxfiles.zip]
 *
 * Unpacks the zip into a temp dir and fails (exit 1, one line per violation) when the
 * build carries something WordPress.org rejects or the free plugin must not contain:
 * a self-updater, paid-module plumbing, a wp-load.php include, files the plugin never
 * runs, hidden or executable files, a stray cURL call, a CDN script, an oversized zip or a readme that breaks the
 * directory's rules. vendor/ is third-party code and is exempt from the string scans.
 */

const MAX_ZIP_BYTES = 8 * 1024 * 1024;

$root = dirname(__DIR__);
$zipPath = $argv[1] ?? $root . '/build/fluxfiles.zip';
$violations = [];
$fail = static function (string $msg) use (&$violations): void {
    $violations[] = $msg;
};

if (!is_file($zipPath)) {
    fwrite(STDERR, "zip not found: $zipPath (run scripts/build-wordpress.sh first)\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ext-zip is required\n");
    exit(2);
}

$size = (int) filesize($zipPath);
if ($size > MAX_ZIP_BYTES) {
    $fail(sprintf('zip is %.2f MB, over the 8 MB limit', $size / 1048576));
}

$tmp = sys_get_temp_dir() . '/fluxfiles-dist-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "cannot open $zipPath\n");
    exit(2);
}
$zip->extractTo($tmp);
$zip->close();

$base = $tmp . '/fluxfiles';
if (!is_dir($base)) {
    fwrite(STDERR, "zip has no top-level fluxfiles/ folder\n");
    exit(2);
}

// ---- file list -------------------------------------------------------------
$files = [];   // relative path => absolute path
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $files[str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1))] = $f->getPathname();
    }
}
ksort($files);

// ---- required paths --------------------------------------------------------
foreach ([
    'fluxfiles.php', 'readme.txt', 'LICENSE', 'docs/THIRD-PARTY-NOTICES.md',
    'api/FileManager.php', 'templates/ui-index.php', 'assets/fm.js', 'assets/fm.min.js',
    'assets/fluxfiles.js', 'assets/vendor/alpine/alpine.min.js',
    'assets/fonts/DejaVuSans.ttf', 'assets/codemirror-theme-material-darker.css',
    'vendor/autoload.php', 'composer.json', 'composer.lock', 'docs/HOOKS.md',
] as $req) {
    if (!isset($files[$req])) {
        $fail("required file missing: $req");
    }
}

// ---- UI template guard -----------------------------------------------------
// templates/ui-index.php must open with the exact line FluxFilesApi::UI_TEMPLATE_GUARD
// strips when serving; without it the page is openable directly under wp-content/plugins/.
if (isset($files['templates/ui-index.php']) && isset($files['includes/FluxFilesApi.php'])) {
    $api = (string) file_get_contents($files['includes/FluxFilesApi.php']);
    if (preg_match('/const UI_TEMPLATE_GUARD = "(.*)\\\\n";/', $api, $m) !== 1) {
        $fail('includes/FluxFilesApi.php: UI_TEMPLATE_GUARD constant not found');
    } else {
        $guard = $m[1] . "\n";
        if (!str_starts_with((string) file_get_contents($files['templates/ui-index.php']), $guard)) {
            $fail('templates/ui-index.php does not start with the exact UI_TEMPLATE_GUARD line');
        }
    }
}

// ---- forbidden paths -------------------------------------------------------
foreach ($files as $rel => $_) {
    foreach ([
        '#^vendor/fluxfiles/#' => 'paid modules must not be bundled',
        '#(^|/)modules-autoload\.php$#' => 'module autoloader',
        '#^api/index\.php$#' => 'standalone entry point',
        '#^embed\.php$#' => 'standalone token helper',
        '#^config/#' => 'standalone disk config',
        '#^public/(share|intake)\.html$#' => 'recipient pages belong to the paid modules',
        '#^api/helpers\.php$#' => 'only the standalone api/index.php requires it',
        '#^api/(LicenseManager|LicenseKeyFile|LicenseRefresh|UpdateClient|OidcDiscovery|SsoStateToken|SsoBootToken|DemoMode|TusServer)\.php$#' => 'core file the plugin never runs',
        '#^assets/vendor/codemirror/#' => 'WordPress ships CodeMirror (guideline 13)',
        '#(^|/)tests?/#i' => 'tests',
        '#(^|/)\.git#' => 'git metadata',
        '#(^|/)phpunit#i' => 'phpunit',
        '#(^|/)\.distignore$#' => 'build metadata',
        '#^(?!assets/|vendor/).*\.(html?|svg|xml)$#i' => 'raw-servable page outside assets/ (UI pages must be PHP templates that exit without ABSPATH)',
        '#^public-link\.php$#' => 'the legacy redirect entry point was removed in 1.1.0',
        // Plugin Check: hidden_files, unexpected_markdown_file, application_detected.
        '#(^|/)\.[^/]+#' => 'hidden file or directory (hidden_files)',
        '#^[^/]+\.md$#' => 'markdown in the plugin root (unexpected_markdown_file); ship it under docs/',
        '#\.(sh|phar|exe|bat|so|dll|dylib)$#i' => 'executable or archive (application_detected)',
        '#(^|/)bin/#' => 'bin/ launcher directory (application_detected)',
    ] as $re => $why) {
        if (preg_match($re, $rel) === 1) {
            $fail("forbidden path: $rel ($why)");
        }
    }
}

// ---- content scans (outside vendor/) ---------------------------------------
$binary = '#\.(ttf|otf|woff2?|png|jpe?g|gif|webp|ico|mo|zip|gz)$#i';
$strings = [
    'Update URI' => 'self-update channel',
    'pre_set_site_transient_update_plugins' => 'self-update channel',
    'site_transient_update_plugins' => 'self-update channel',
    'upgrader_pre_download' => 'self-update channel',
    'upgrader_post_install' => 'self-update channel',
    'plugins_api' => 'self-update channel',
    'http_request_args' => 'self-update channel',
    'class FixityChecker' => 'moved to the audit-export module',
];
// Implementations that moved into the modules. A bodiless declaration (the provider
// interfaces) and the thin REST route stubs (handleSharePublicRoute) are fine.
$movedFunctions = [
    'runRetention' => 'legal-hold',
    'runFixityCheck' => 'audit-export',
    'handleSharePublic' => 'share',
    'handleIntakePublic' => 'intake',
];
$curlOk = ['api/UrlImporter.php', 'api/CurlTransport.php', 'api/BucketDoctor.php'];
$socketOk = ['api/SsrfGuard.php', 'api/UrlImporter.php', 'api/CurlTransport.php', 'api/BucketDoctor.php'];

foreach ($files as $rel => $abs) {
    if (str_starts_with($rel, 'vendor/') || preg_match($binary, $rel) === 1) {
        continue;
    }
    $src = (string) file_get_contents($abs);

    foreach ($strings as $needle => $why) {
        if (str_contains($src, $needle)) {
            $fail("$rel: contains '$needle' ($why)");
        }
    }
    if (str_contains($src, 'wp-load.php') || str_contains($src, 'wp-load')) {
        $fail("$rel: references wp-load.php");
    }
    foreach ($movedFunctions as $fn => $module) {
        if (preg_match('/\bfunction\s+' . $fn . '\s*\([^)]*\)\s*(:\s*[\w\\\\?|]+\s*)?\{/', $src) === 1) {
            $fail("$rel: implements $fn() (moved to the $module module)");
        }
    }
    if (preg_match('/\bclass\s+LicenseManager\b/', $src) === 1) {
        $fail("$rel: defines class LicenseManager");
    }
    if (str_ends_with($rel, '.php')) {
        if (preg_match('/\bcurl_(?:exec|multi_init|init)\s*\(/', $src) === 1 && !in_array($rel, $curlOk, true)) {
            $fail("$rel: curl_* call outside " . implode(', ', $curlOk));
        }
        if (preg_match('/\b(?:fsockopen|pfsockopen|stream_socket_client)\s*\(/', $src) === 1 && !in_array($rel, $socketOk, true)) {
            $fail("$rel: raw socket call outside " . implode(', ', $socketOk));
        }
        if (preg_match('/\beval\s*\(/', $src) === 1) {
            $fail("$rel: eval(");
        }
        if (preg_match('/\b(?:assert|create_function)\s*\(\s*["\'$]/', $src) === 1) {
            $fail("$rel: assert()/create_function() with a string argument (code execution)");
        }
        foreach (preg_split('/\R/', $src) ?: [] as $line) {
            if (stripos($line, 'base64_decode') !== false && preg_match('/\b(?:eval|assert|create_function|preg_replace)\s*\(/', $line) === 1) {
                $fail("$rel: base64_decode combined with eval on one line");
                break;
            }
        }
        // wp-load assembled from pieces ('wp-' . 'load'), which the plain wp-load scan misses.
        if (preg_match('/["\']wp-?["\']\s*\.\s*["\']-?load|wp-["\']\s*\.\s*["\']load|["\']wp["\']\s*\.\s*["\']-["\']\s*\.\s*["\']load/i', $src) === 1) {
            $fail("$rel: builds a wp-load.php path by concatenation");
        }
    }
    if (preg_match('#<script[^>]+src\s*=\s*["\']?(?:https?:)?//#i', $src) === 1) {
        $fail("$rel: <script src=\"http...\"> loads external code");
    }
    if (preg_match('#<link[^>]+href\s*=\s*["\']?(?:https?:)?//#i', $src) === 1) {
        $fail("$rel: <link href=\"http...\"> loads an external stylesheet");
    }
    if (preg_match('#@import\s+(?:url\()?["\']?(?:https?:)?//#i', $src) === 1) {
        $fail("$rel: CSS @import of an external URL");
    }
    if (str_ends_with($rel, '.js') && preg_match('#\.src\s*=\s*["\'](?:https?:)?//#', $src) === 1) {
        $fail("$rel: script .src assigned an external URL");
    }
    if ($rel === 'api/AuditChain.php' && str_contains($src, 'function verify(')) {
        $fail("$rel: function verify( moved to the audit-export module");
    }
}
// ---- readme.txt and header -------------------------------------------------
if (isset($files['readme.txt']) && isset($files['fluxfiles.php'])) {
    $readme = (string) file_get_contents($files['readme.txt']);
    $main = (string) file_get_contents($files['fluxfiles.php']);
    $h = static function (string $text, string $key): string {
        return preg_match('/^[ \t\/*#@]*' . preg_quote($key, '/') . '[ \t]*:[ \t]*(.+?)[ \t]*$/mi', $text, $m) === 1 ? $m[1] : '';
    };
    $version = $h($main, 'Version');
    $stable = $h($readme, 'Stable tag');
    if ($version === '' || $stable !== $version) {
        $fail("readme Stable tag '$stable' != plugin header Version '$version'");
    }
    if (preg_match("/define\\('FLUXFILES_VERSION',\\s*'([^']*)'/", $main, $m) === 1 && $m[1] !== $version) {
        $fail("FLUXFILES_VERSION '{$m[1]}' != plugin header Version '$version'");
    }
    if ($h($main, 'Text Domain') !== 'fluxfiles') {
        $fail("plugin header 'Text Domain' must be fluxfiles");
    }
    $tags = array_filter(array_map('trim', explode(',', $h($readme, 'Tags'))), 'strlen');
    if (count($tags) > 5) {
        $fail('readme has ' . count($tags) . ' tags (max 5)');
    }
    if (preg_match('/^\d+\.\d+/', $h($readme, 'Tested up to')) !== 1) {
        $fail("readme 'Tested up to' must be major.minor, got '" . $h($readme, 'Tested up to') . "'");
    }
    foreach (['Requires at least', 'Requires PHP', 'License', 'Contributors'] as $k) {
        if ($h($readme, $k) === '') {
            $fail("readme header '$k' is missing");
        }
    }
    // Short description: first non-empty line after the header block.
    $parts = preg_split('/\R\s*\R/', ltrim(preg_replace('/^===.*?===\s*/', '', $readme, 1) ?? ''), 2);
    $after = trim((string) ($parts[1] ?? ''));
    $short = trim(strtok($after, "\n") ?: '');
    if ($short === '' || str_starts_with($short, '==')) {
        $fail('readme has no short description');
    } elseif (mb_strlen($short) > 150) {
        $fail('readme short description is ' . mb_strlen($short) . ' characters (max 150)');
    }
    foreach (['External services', 'Source & build'] as $sec) {
        if (!preg_match('/^==\s*' . preg_quote($sec, '/') . '\s*==\s*$/mi', $readme)) {
            $fail("readme is missing the '== $sec ==' section");
        }
    }
}

// ---- report ----------------------------------------------------------------
printf("checked %d files, zip %.2f MB\n", count($files), $size / 1048576);
if ($violations) {
    foreach ($violations as $v) {
        echo "FAIL: $v\n";
    }
    printf("%d violation(s)\n", count($violations));
    exit(1);
}
echo "OK: the free-plugin zip passes the WordPress.org guard\n";
