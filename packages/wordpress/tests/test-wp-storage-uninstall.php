<?php

/**
 * WordPress plugin: storage base decision (fluxfiles_storage_base), the local
 * disk URL derived from it, the hidden protection files, and uninstall.php.
 *
 * Every scenario runs in its own PHP process (WP_CONTENT_DIR /
 * FLUXFILES_STORAGE_DIR are process-global constants) against a stubbed WordPress.
 *
 * Usage (from repo root, after `composer install -d packages/core`):
 *   php packages/wordpress/tests/test-wp-storage-uninstall.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

function rrm(string $d): void
{
    if (is_link($d) || is_file($d)) { @unlink($d); return; }
    if (!is_dir($d)) { return; }
    foreach (scandir($d) ?: [] as $f) { if ($f !== '.' && $f !== '..') { rrm($d . '/' . $f); } }
    @rmdir($d);
}

$coreAutoload = getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php';
$pluginDir = realpath(__DIR__ . '/..');

/** Stubbed-WordPress prelude shared by every scenario (config arrives via $FXT). */
$prelude = <<<'PHP'
<?php
$FXT = json_decode((string) getenv('FXT'), true);
define('ABSPATH', sys_get_temp_dir() . '/');
define('WP_CONTENT_DIR', $FXT['content']);
define('WP_PLUGIN_DIR', $FXT['content'] . '/plugins');
define('FLUXFILES_PLUGIN_FILE', 'x');
define('FLUXFILES_VERSION', '1.0.0');
if (!empty($FXT['const'])) { define('FLUXFILES_STORAGE_DIR', $FXT['const']); }
if (!empty($FXT['uninstall'])) { define('WP_UNINSTALL_PLUGIN', 'fluxfiles/fluxfiles.php'); }
$GLOBALS['CUR'] = 1;
$GLOBALS['OPT'] = $FXT['options'] ?? [];      // blog id => [name => value]
$GLOBALS['SOPT'] = $FXT['site_options'] ?? []; // network options
function wp_generate_password($n = 12, $s = true) { return str_repeat('a', $n); }
$GLOBALS['LOG'] = ['delete_option' => [], 'delete_site_option' => [], 'queries' => [], 'switch' => [], 'registered' => [], 'sites_calls' => 0, 'sites_args' => [], 'deleted_files' => []];
$GLOBALS['STACK'] = [];
$multi = !empty($FXT['multisite']);
function get_option($k, $d = false) { return $GLOBALS['OPT'][$GLOBALS['CUR']][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['OPT'][$GLOBALS['CUR']][$k] = $v; return true; }
function delete_option($k) { $GLOBALS['LOG']['delete_option'][$GLOBALS['CUR']][] = $k; unset($GLOBALS['OPT'][$GLOBALS['CUR']][$k]); return true; }
function get_site_option($k, $d = false) { return $GLOBALS['SOPT'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['SOPT'][$k] = $v; return true; }
function delete_site_option($k) { $GLOBALS['LOG']['delete_site_option'][] = $k; unset($GLOBALS['SOPT'][$k]); return true; }
function add_filter($t, $cb, $p = 10, $a = 1) { $GLOBALS['F'][$t][] = $cb; return true; }
function apply_filters($t, $v, ...$a) {
    global $FXT;
    if ($t === 'fluxfiles_storage_base' && !empty($FXT['filter'])) { return $FXT['filter']; }
    return $v;
}
function add_action(...$a) {}
function do_action(...$a) {}
function content_url($p = '') { return 'http://wp.test/wp-content/' . ltrim($p, '/'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function is_multisite() { global $multi; return $multi; }
function get_current_blog_id() { return $GLOBALS['CUR']; }
function get_main_site_id() { return 1; }
function get_current_network_id() { return 7; }
function wp_delete_file($f) { $GLOBALS['LOG']['deleted_files'][] = $f; if (is_file($f) || is_link($f)) { unlink($f); } }
function switch_to_blog($id) { $GLOBALS['STACK'][] = $GLOBALS['CUR']; $GLOBALS['CUR'] = (int) $id; $GLOBALS['LOG']['switch'][] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['CUR'] = array_pop($GLOBALS['STACK']) ?? 1; return true; }
function get_sites($args = []) {
    global $FXT;
    $GLOBALS['LOG']['sites_calls']++;
    $GLOBALS['LOG']['sites_args'][] = $args['network_id'] ?? null;
    $all = range(1, (int) ($FXT['site_count'] ?? 1));
    return array_slice($all, (int) ($args['offset'] ?? 0), (int) ($args['number'] ?? 100) ?: null);
}
if (empty($FXT['no_upload_api'])) {
    function wp_upload_dir($t = null, $c = true) {
        global $FXT;
        $sub = $GLOBALS['CUR'] === 1 ? '' : '/sites/' . $GLOBALS['CUR'];   // WP gives sub-sites uploads/sites/N
        if (!empty($FXT['stream_uploads'])) { return ['basedir' => 's3://bucket/uploads', 'baseurl' => 'https://cdn.test/uploads', 'error' => false]; }
        return ['basedir' => $FXT['content'] . '/uploads' . $sub, 'baseurl' => 'http://wp.test/wp-content/uploads' . $sub, 'error' => false];
    }
}
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return $s; }
function register_setting($g, $n, $a = []) { $GLOBALS['LOG']['registered'][] = $n; }
function add_settings_section(...$a) {}
function add_settings_field(...$a) {}
class FxWpdb {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($q, ...$a) { return vsprintf(str_replace('%s', "'%s'", $q), $a); }
    public function query($q) { $GLOBALS['LOG']['queries'][] = ['blog' => $GLOBALS['CUR'], 'sql' => $q]; return 1; }
}
$wpdb = new FxWpdb();
function fx_out($v) { echo "\n@@" . json_encode($v) . "@@\n"; }
PHP;

/**
 * Run $body (PHP statements) after the prelude + plugin classes; the body calls
 * fx_out($result). Returns the decoded result.
 *
 * @param array<string,mixed> $cfg
 * @return array<string,mixed>
 */
function scenario(array $cfg, string $body, bool $core = false): array
{
    global $prelude, $pluginDir, $coreAutoload;
    $script = $prelude . "\n"
        . ($core ? "require_once " . var_export($coreAutoload, true) . ";\n" : '')
        . "require_once " . var_export($pluginDir . '/includes/FluxFilesPlugin.php', true) . ";\n"
        . "require_once " . var_export($pluginDir . '/includes/FluxFilesDbSchema.php', true) . ";\n"
        . $body;
    $file = tempnam(sys_get_temp_dir(), 'fxs') . '.php';
    file_put_contents($file, $script);
    $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['FXT' => json_encode($cfg), 'PATH' => getenv('PATH') ?: '']);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    @unlink($file);
    if (!preg_match('/@@(.*)@@/s', $out, $m)) {
        throw new \RuntimeException("scenario produced no result.\nstdout: {$out}\nstderr: {$err}");
    }
    return json_decode($m[1], true);
}

function tmpTree(): string
{
    $d = sys_get_temp_dir() . '/fx-storage-' . uniqid();
    mkdir($d . '/wp-content', 0777, true);
    return $d;
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles WordPress storage base / uninstall\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

$probe = 'fx_out(["path" => FluxFilesPlugin::storagePath(), "disk" => FluxFilesPlugin::diskConfigs()["local"], '
    . '"opt" => get_site_option("fluxfiles_storage_base", null), "rel" => FluxFilesPlugin::uploadsRelPath()]);';

test('existing wp-content/fluxfiles is kept (no data move) and persisted', function () use ($probe) {
    $t = tmpTree();
    mkdir($t . '/wp-content/fluxfiles/uploads', 0777, true);
    $r = scenario(['content' => $t . '/wp-content'], $probe);
    assertEqual($t . '/wp-content/fluxfiles', $r['path']);
    assertEqual($t . '/wp-content/fluxfiles/uploads', $r['disk']['root']);
    assertEqual('http://wp.test/wp-content/fluxfiles/uploads', $r['disk']['url']);
    assertEqual('fluxfiles/uploads', $r['rel']);
    assertEqual('content', $r['opt'], 'a location token is stored, never an absolute path');
    assertTrue($r['disk']['private'] === false, 'public url => not private by default');
    rrm($t);
});

test('fresh install lands in uploads/fluxfiles, url under the uploads baseurl', function () use ($probe) {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content'], $probe);
    assertEqual($t . '/wp-content/uploads/fluxfiles', $r['path']);
    assertEqual('http://wp.test/wp-content/uploads/fluxfiles/uploads', $r['disk']['url']);
    assertEqual('fluxfiles/uploads', $r['rel'], 'relative to the uploads dir');
    assertEqual('uploads', $r['opt'], 'token, not a path');
    rrm($t);
});

test('the decision is made once: a later wp-content/fluxfiles does not move a fresh install', function () {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content'], 'FluxFilesPlugin::storagePath(); mkdir(WP_CONTENT_DIR . "/fluxfiles", 0777, true); fx_out(["p" => FluxFilesPlugin::storagePath()]);');
    assertEqual($t . '/wp-content/uploads/fluxfiles', $r['p']);
    rrm($t);
});

test('a stored option wins over the directory check', function () use ($probe) {
    $t = tmpTree();
    mkdir($t . '/wp-content/fluxfiles', 0777, true);
    $r = scenario(['content' => $t . '/wp-content', 'site_options' => ['fluxfiles_storage_base' => 'uploads']], $probe);
    assertEqual($t . '/wp-content/uploads/fluxfiles', $r['path']);
    rrm($t);
});

test('the stored token follows the site when it moves (WP_CONTENT_DIR changes); it never flips', function () use ($probe) {
    $a = tmpTree();
    $b = tmpTree();
    foreach (['uploads' => '/wp-content/uploads/fluxfiles', 'content' => '/wp-content/fluxfiles'] as $token => $rel) {
        foreach ([$a, $b] as $t) {
            // a legacy folder next to an 'uploads' token must not flip the decision
            @mkdir($t . '/wp-content/fluxfiles', 0777, true);
            $r = scenario(['content' => $t . '/wp-content', 'site_options' => ['fluxfiles_storage_base' => $token]], $probe);
            assertEqual($t . $rel, $r['path'], "token {$token}");
            assertEqual($token, $r['opt'], 'option untouched');
        }
    }
    // uploads token + no uploads API: the uploads-shaped fallback, never the legacy path
    $r = scenario(['content' => $a . '/wp-content', 'no_upload_api' => true, 'site_options' => ['fluxfiles_storage_base' => 'uploads']], $probe);
    assertEqual($a . '/wp-content/uploads/fluxfiles', $r['path']);
    // a pre-release absolute value is honoured as-is
    $r = scenario(['content' => $b . '/wp-content', 'site_options' => ['fluxfiles_storage_base' => $a . '/old/abs']], $probe);
    assertEqual($a . '/old/abs', $r['path']);
    rrm($a);
    rrm($b);
});

test('the uploads info is resolved once per request (no repeated switch_to_blog)', function () {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content', 'multisite' => true],
        'switch_to_blog(2); for ($i = 0; $i < 5; $i++) { FluxFilesStorage::base(); FluxFilesStorage::url(FluxFilesStorage::base() . "/uploads"); } restore_current_blog(); fx_out($GLOBALS["LOG"]["switch"]);');
    assertEqual([2, 1], $r, 'one switch to the main site for the whole request (plus the test\'s own)');
    rrm($t);
});

test('override order: constant > filter > option', function () use ($probe) {
    $t = tmpTree();
    $opt = ['fluxfiles_storage_base' => 'uploads'];
    $f = scenario(['content' => $t . '/wp-content', 'site_options' => $opt, 'filter' => $t . '/filtered/'], $probe);
    assertEqual($t . '/filtered', $f['path'], 'filter beats option (trailing slash trimmed)');
    $c = scenario(['content' => $t . '/wp-content', 'site_options' => $opt, 'filter' => $t . '/filtered', 'const' => $t . '/const'], $probe);
    assertEqual($t . '/const', $c['path'], 'constant beats filter');
    rrm($t);
});

test('a base outside uploads/ and wp-content/ has no public url and goes private', function () use ($probe) {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content', 'const' => $t . '/outside'], $probe);
    assertEqual('', $r['disk']['url']);
    assertTrue($r['disk']['private'] === true, 'served through /stream only');
    assertEqual('', $r['rel']);
    rrm($t);
});

test('a base under wp-content (not uploads) keeps the content_url form', function () use ($probe) {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content', 'const' => $t . '/wp-content/vault/ff'], $probe);
    assertEqual('http://wp.test/wp-content/vault/ff/uploads', $r['disk']['url']);
    assertEqual('vault/ff/uploads', $r['rel']);
    rrm($t);
});

test('without the uploads API the uploads-shaped fallback is used and nothing is persisted', function () use ($probe) {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content', 'no_upload_api' => true], $probe);
    assertEqual($t . '/wp-content/uploads/fluxfiles', $r['path']);
    assertEqual(null, $r['opt']);
    rrm($t);
});

test('multisite: base is network-wide (main-site uploads), per-blog layout stays sites/<id>', function () {
    $t = tmpTree();
    $body = 'foreach ([1, 2] as $b) { switch_to_blog($b); $d = FluxFilesPlugin::diskConfigs()["local"]; $o[$b] = ["path" => FluxFilesPlugin::storagePath(), "root" => $d["root"], "url" => $d["url"]]; restore_current_blog(); } fx_out($o);';
    // Visit blog 2 FIRST so the decision is taken while a sub-site is current.
    $r = scenario(['content' => $t . '/wp-content', 'multisite' => true], 'switch_to_blog(2); FluxFilesPlugin::storagePath(); restore_current_blog();' . $body);
    $base = $t . '/wp-content/uploads/fluxfiles';
    assertEqual($base . '/sites/1', $r[1]['path']);
    assertEqual($base . '/sites/2', $r[2]['path']);
    assertEqual($base . '/sites/2/uploads', $r[2]['root']);
    assertEqual('http://wp.test/wp-content/uploads/fluxfiles/sites/2/uploads', $r[2]['url']);
    rrm($t);

    $t = tmpTree();
    mkdir($t . '/wp-content/fluxfiles/sites/2', 0777, true);
    $r = scenario(['content' => $t . '/wp-content', 'multisite' => true], $body);
    assertEqual($t . '/wp-content/fluxfiles/sites/2', $r[2]['path'], 'existing multisite layout kept');
    rrm($t);
});

test('protectStorage writes into the resolved base', function () {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content'], '$p = FluxFilesPlugin::storagePath(); wp_mkdir_p($p . "/uploads"); FluxFilesPlugin::protectStorage(); fx_out(["root" => is_file($p . "/.htaccess"), "up" => is_file($p . "/uploads/.htaccess"), "idx" => is_file($p . "/uploads/index.php"), "p" => $p]);');
    assertTrue($r['root'] && $r['up'] && $r['idx'], 'guard files written');
    assertTrue(str_starts_with($r['p'], $t . '/wp-content/uploads/'), 'under uploads');
    rrm($t);
});

test('guard files are hidden at the disk root only; a user file of that name in a subfolder stays', function () {
    $t = tmpTree();
    $body = '$p = FluxFilesPlugin::storagePath(); wp_mkdir_p($p . "/uploads/sub"); FluxFilesPlugin::protectStorage();'
        . 'file_put_contents($p . "/uploads/user.txt", "hello"); file_put_contents($p . "/uploads/sub/index.php.txt", "x");'
        . 'file_put_contents($p . "/uploads/sub/.htaccess", "mine"); file_put_contents($p . "/uploads/sub/index.php", "mine");'
        . '$dm = new \FluxFiles\DiskManager(FluxFilesPlugin::diskConfigs());'
        . '$claims = new \FluxFiles\Claims("u", ["read"], ["local"], "", 50, null, 0, false); $claims->showHidden = true;'
        . '$fm = new \FluxFiles\FileManager($dm, $claims, new \FluxFiles\StorageMetadataHandler($dm));'
        . '$names = fn ($path) => array_values(array_map(fn ($i) => $i["name"], $fm->list("local", $path)));'
        . '$walk = []; foreach ($fm->visibleFiles("local") as $f) { $walk[] = is_array($f) ? ($f["key"] ?? "") : (string) $f; }'
        . 'fx_out(["root" => $names(""), "sub" => $names("sub"), "walk" => $walk, "onDisk" => is_file($p . "/uploads/.htaccess") && is_file($p . "/uploads/index.php")]);';
    $r = scenario(['content' => $t . '/wp-content'], $body, true);
    assertTrue($r['onDisk'], 'the protection files really exist');
    assertTrue(!in_array('.htaccess', $r['root'], true) && !in_array('index.php', $r['root'], true), 'root listing: ' . json_encode($r['root']));
    assertTrue(in_array('user.txt', $r['root'], true) && in_array('sub', $r['root'], true), 'real content still listed');
    assertTrue(in_array('.htaccess', $r['sub'], true) && in_array('index.php', $r['sub'], true), 'subfolder files of the same name are the user\'s: ' . json_encode($r['sub']));
    assertTrue(!in_array('.htaccess', $r['walk'], true) && !in_array('index.php', $r['walk'], true), 'walk/search/usage feed skips them: ' . json_encode($r['walk']));
    assertTrue(in_array('sub/.htaccess', $r['walk'], true), 'but not the subfolder ones');
    rrm($t);
});

// ── uninstall.php ──────────────────────────────────────────────────────────
$uninstallFile = $pluginDir . '/uninstall.php';
$runUninstall = 'require ' . var_export($uninstallFile, true) . '; fx_out($GLOBALS["LOG"] + ["opt" => $GLOBALS["OPT"], "sopt" => $GLOBALS["SOPT"]]);';

test('uninstall.php is guarded by WP_UNINSTALL_PLUGIN', function () use ($uninstallFile) {
    $src = (string) file_get_contents($uninstallFile);
    assertTrue(strpos($src, "defined('WP_UNINSTALL_PLUGIN') || exit;") !== false, 'guard present');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($uninstallFile) . ' 2>&1');
    assertEqual('', trim((string) $out), 'direct access exits silently');
});

$optIn = [1 => ['fluxfiles_delete_data_on_uninstall' => '1']];
$stubBody = "<?php\n// Silence is golden.\n";

$dataTied = ['fluxfiles_db_version', 'fluxfiles_local_private', 'fluxfiles_secret', 'fluxfiles_storage_backend', 'fluxfiles_storage_base'];

test('every option registered with register_setting is deleted; the data-tied ones only with the opt-in', function () use ($runUninstall, $optIn, $dataTied) {
    $t = tmpTree();
    $cfg = ['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $t . '/base'];
    $reg = scenario($cfg, 'require_once ' . var_export(dirname(__DIR__) . '/includes/FluxFilesAdmin.php', true) . '; (new FluxFilesAdmin())->registerSettings(); fx_out($GLOBALS["LOG"]["registered"]);');
    assertTrue(count($reg) >= 20, 'registered set looks complete: ' . count($reg));
    assertTrue(in_array('fluxfiles_delete_data_on_uninstall', $reg, true), 'opt-in option is a registered setting');
    $u = scenario($cfg, $runUninstall);
    $deleted = $u['delete_option'][1] ?? [];
    $keptReg = array_values(array_diff($reg, $deleted));
    sort($keptReg);
    assertEqual(array_values(array_intersect($dataTied, $reg)), $keptReg, 'without the opt-in only the data-tied options are kept');
    foreach ($dataTied as $name) {
        assertTrue(!in_array($name, $deleted, true), "{$name} kept without the opt-in");
    }
    assertTrue(!in_array('fluxfiles_storage_base', $u['delete_site_option'], true), 'site-level storage base kept without the opt-in');
    $u = scenario($cfg + ['options' => $optIn], $runUninstall);
    assertEqual([], array_values(array_diff($reg, $u['delete_option'][1] ?? [])), 'with the opt-in every registered option goes');
    foreach ($dataTied as $name) {
        assertTrue(in_array($name, $u['delete_option'][1] ?? [], true), "{$name} deleted with the opt-in");
    }
    assertTrue(in_array('fluxfiles_storage_base', $u['delete_site_option'], true), 'site-level storage base deleted with the opt-in');
    rrm($t);
});

test('every get_option()/update_option() name used by the plugin code is deleted with the opt-in (data-tied ones kept without)', function () use ($pluginDir, $runUninstall, $optIn, $dataTied) {
    $used = [];
    foreach (glob($pluginDir . '/includes/*.php') ?: [] as $f) {
        if (preg_match_all("/(?:get|update|add)_(?:site_)?option\(\s*'(fluxfiles_[a-z0-9_]+)'/", (string) file_get_contents($f), $m)) {
            $used = array_merge($used, $m[1]);
        }
    }
    $t = tmpTree();
    $cfg = ['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $t . '/base'];
    $u = scenario($cfg, $runUninstall);
    $kept = array_values(array_diff(array_unique($used), $u['delete_option'][1] ?? []));
    sort($kept);
    assertEqual(array_values(array_intersect($dataTied, $used)), $kept, 'kept without opt-in');
    $u = scenario($cfg + ['options' => $optIn], $runUninstall);
    assertEqual([], array_values(array_diff(array_unique($used), $u['delete_option'][1] ?? [])), 'used but not deleted');
    rrm($t);
});

test('DB: without the opt-in only rate_limits is dropped; with it every table', function () use ($runUninstall, $optIn) {
    $t = tmpTree();
    $cfg = ['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $t . '/base'];
    $u = scenario($cfg, $runUninstall);
    $sql = implode("\n", array_column($u['queries'], 'sql'));
    assertTrue(strpos($sql, 'DROP TABLE IF EXISTS `wp_fluxfiles_rate_limits`') !== false, 'drops rate_limits');
    foreach (['file_metadata', 'directories', 'trash', 'audit_log', 'audit_chain_head', 'legal_holds'] as $tbl) {
        assertTrue(strpos($sql, 'wp_fluxfiles_' . $tbl . '`') === false, "keeps {$tbl}");
    }
    assertTrue(strpos(str_replace('\\', '', $sql), '_transient_fluxfiles_usage_') !== false, 'usage transients removed');
    $u = scenario($cfg + ['options' => $optIn], $runUninstall);
    $sql = implode("\n", array_column($u['queries'], 'sql'));
    foreach (['file_metadata', 'directories', 'trash', 'audit_log', 'audit_chain_head', 'legal_holds', 'rate_limits'] as $tbl) {
        assertTrue(strpos($sql, 'DROP TABLE IF EXISTS `wp_fluxfiles_' . $tbl . '`') !== false, "drops {$tbl}");
    }
    rrm($t);
});

/** The plugin's own location: the 'uploads' token => <wp-content>/uploads/fluxfiles. */
function ownBase(string $t): string { return $t . '/wp-content/uploads/fluxfiles'; }

function ownBaseCfg(string $t, $flag, string $token = 'uploads'): array
{
    return ['content' => $t . '/wp-content', 'uninstall' => true, 'site_options' => ['fluxfiles_storage_base' => $token],
        'options' => $flag === null ? [] : [1 => ['fluxfiles_delete_data_on_uninstall' => $flag]]];
}

/** A pre-release absolute value ('legacy' source): never deleted from. */
function legacyCfg(string $t, string $base, $flag): array
{
    return ['content' => $t . '/wp-content', 'uninstall' => true, 'site_options' => ['fluxfiles_storage_base' => $base],
        'options' => $flag === null ? [] : [1 => ['fluxfiles_delete_data_on_uninstall' => $flag]]];
}

test('stored files are kept unless fluxfiles_delete_data_on_uninstall is on', function () use ($runUninstall, $stubBody) {
    foreach ([[null, true], ['0', true], ['1', false]] as [$flag, $kept]) {
        $t = tmpTree();
        $base = ownBase($t);
        mkdir($base . '/uploads/sub', 0777, true);
        file_put_contents($base . '/uploads/sub/photo.jpg', 'bytes');
        file_put_contents($base . '/uploads/.htaccess', 'x');
        file_put_contents($base . '/.htaccess', "# FluxFiles storage protection v1\nRequire all denied\n");
        file_put_contents($base . '/index.php', $stubBody);
        file_put_contents($base . '/keep-me.txt', 'unrelated');
        scenario(ownBaseCfg($t, $flag), $runUninstall);
        assertEqual($kept, is_file($base . '/uploads/sub/photo.jpg'), 'flag ' . var_export($flag, true));
        assertTrue(is_file($base . '/keep-me.txt'), 'unrelated files in the base survive');
        if (!$kept) {
            assertTrue(!is_dir($base . '/uploads') && !is_file($base . '/index.php') && !is_file($base . '/.htaccess'), 'plugin-owned files removed');
        }
        rrm($t);
    }
});

test('an operator-authored .htaccess / index.php survives the wipe', function () use ($runUninstall) {
    $t = tmpTree();
    $base = ownBase($t);
    mkdir($base . '/uploads', 0777, true);
    file_put_contents($base . '/.htaccess', "Options -Indexes\n# my rules\n");
    file_put_contents($base . '/index.php', "<?php // my own front controller\n");
    scenario(ownBaseCfg($t, '1'), $runUninstall);
    assertEqual("Options -Indexes\n# my rules\n", (string) @file_get_contents($base . '/.htaccess'), '.htaccess kept');
    assertEqual("<?php // my own front controller\n", (string) @file_get_contents($base . '/index.php'), 'index.php kept');
    assertTrue(!is_dir($base . '/uploads'), 'the uploads tree still goes');
    rrm($t);
});

test('a legacy base that equals / contains uploads, wp-content or ABSPATH is never wiped', function () use ($runUninstall) {
    foreach (['uploads' => '/wp-content/uploads', 'content' => '/wp-content', 'plugins' => '/wp-content/plugins'] as $label => $rel) {
        $t = tmpTree();
        $base = $t . $rel;
        mkdir($base . '/uploads/sub', 0777, true);
        file_put_contents($base . '/uploads/sub/photo.jpg', 'bytes');
        file_put_contents($base . '/index.php', "<?php\n// Silence is golden.\n");
        scenario(legacyCfg($t, $base, '1'), $runUninstall);
        assertTrue(is_file($base . '/uploads/sub/photo.jpg') && is_file($base . '/index.php'), "{$label}: nothing deleted");
        rrm($t);
    }
    // the root that CONTAINS wp-content (here the tree itself)
    $t = tmpTree();
    mkdir($t . '/uploads', 0777, true);
    file_put_contents($t . '/uploads/photo.jpg', 'bytes');
    scenario(legacyCfg($t, $t, '1'), $runUninstall);
    assertTrue(is_file($t . '/uploads/photo.jpg') && is_dir($t . '/wp-content'), 'parent of wp-content: nothing deleted');
    rrm($t);
});

test('a base from the constant or the filter is never wiped, even with the opt-in', function () use ($runUninstall, $optIn) {
    foreach (['const', 'filter'] as $how) {
        $t = tmpTree();
        $base = $t . '/elsewhere';
        mkdir($base . '/uploads', 0777, true);
        file_put_contents($base . '/uploads/photo.jpg', 'bytes');
        file_put_contents($base . '/.htaccess', "# FluxFiles storage protection v1\n");
        $u = scenario(['content' => $t . '/wp-content', 'uninstall' => true, $how => $base, 'options' => $optIn], $runUninstall);
        assertTrue(is_file($base . '/uploads/photo.jpg') && is_file($base . '/.htaccess'), "{$how}: files kept");
        assertTrue(in_array('fluxfiles_secret', $u['delete_option'][1] ?? [], true), "{$how}: DB/settings cleanup still runs");
        rrm($t);
    }
});

test('a symlink inside the tree is unlinked, never followed', function () use ($runUninstall) {
    $t = tmpTree();
    $base = ownBase($t);
    mkdir($base . '/uploads', 0777, true);
    mkdir($t . '/outside', 0777, true);
    file_put_contents($t . '/outside/precious.txt', 'keep');
    symlink($t . '/outside', $base . '/uploads/link');
    symlink($t . '/outside/precious.txt', $base . '/uploads/flink');
    scenario(ownBaseCfg($t, '1'), $runUninstall);
    assertTrue(is_file($t . '/outside/precious.txt'), 'link target untouched');
    assertTrue(!is_link($base . '/uploads/link') && !is_dir($base . '/uploads'), 'links removed with the tree');
    rrm($t);
});

test('multisite: every site is visited in batches (this network only), per-site opt-in decides its files, network options go', function () use ($runUninstall) {
    $t = tmpTree();
    $base = ownBase($t);
    foreach ([1, 2, 3] as $b) {
        mkdir($base . "/sites/{$b}/uploads", 0777, true);
        file_put_contents($base . "/sites/{$b}/uploads/f.txt", 'x');
    }
    $cfg = ['content' => $t . '/wp-content', 'uninstall' => true, 'multisite' => true, 'site_count' => 250,
        'options' => [2 => ['fluxfiles_delete_data_on_uninstall' => '1']],
        'site_options' => ['fluxfiles_storage_base' => 'uploads']];
    $u = scenario($cfg, $runUninstall);
    assertEqual(range(1, 250), $u['switch'], 'all 250 sites visited');
    assertEqual(3, $u['sites_calls'], 'get_sites paged (100/100/50, never a single unbounded call)');
    assertEqual([7, 7, 7], $u['sites_args'], 'scoped to the current network');
    assertTrue(!in_array('fluxfiles_secret', $u['delete_option'][250] ?? [], true) && in_array('fluxfiles_max_upload', $u['delete_option'][250] ?? [], true), 'last site cleaned (data-tied options kept: no opt-in)');
    assertTrue(in_array('fluxfiles_secret', $u['delete_option'][2] ?? [], true), 'opted-in site loses its secret');
    $dropped = array_filter($u['queries'], fn ($q) => str_contains($q['sql'], 'DROP TABLE') && $q['blog'] === 2);
    assertEqual(7, count($dropped), 'opted-in site: every table dropped');
    $kept = array_filter($u['queries'], fn ($q) => str_contains($q['sql'], 'DROP TABLE') && $q['blog'] === 3);
    assertEqual(1, count($kept), 'other sites: rate_limits only');
    assertTrue(!is_dir($base . '/sites/2/uploads'), 'opted-in site 2 files removed');
    assertTrue(is_file($base . '/sites/1/uploads/f.txt') && is_file($base . '/sites/3/uploads/f.txt'), 'other sites keep their files');
    assertTrue(!in_array('fluxfiles_storage_base', $u['delete_site_option'], true), 'network storage base kept while some site keeps its data');
    rrm($t);

    $t = tmpTree();
    $all = ['fluxfiles_delete_data_on_uninstall' => '1'];
    $u = scenario(['content' => $t . '/wp-content', 'uninstall' => true, 'multisite' => true, 'site_count' => 3,
        'options' => [1 => $all, 2 => $all, 3 => $all], 'site_options' => ['fluxfiles_storage_base' => 'uploads']], $runUninstall);
    assertTrue(in_array('fluxfiles_storage_base', $u['delete_site_option'], true), 'network storage base deleted once every site opted in');
    rrm($t);
});

test('a legacy absolute base (even a safe one) is never wiped, with the opt-in', function () use ($runUninstall, $probe) {
    $t = tmpTree();
    $base = $t . '/own/fluxfiles';
    mkdir($base . '/uploads', 0777, true);
    file_put_contents($base . '/uploads/photo.jpg', 'bytes');
    scenario(legacyCfg($t, $base, '1'), $runUninstall);
    assertTrue(is_file($base . '/uploads/photo.jpg'), 'legacy files kept');
    $r = scenario(['content' => $t . '/wp-content', 'site_options' => ['fluxfiles_storage_base' => $base]], $probe . ' ');
    assertEqual($base, $r['path'], 'still honoured');
    rrm($t);
});

test('a non-token, non-absolute stored value is junk: decided again', function () use ($probe) {
    $t = tmpTree();
    foreach (['relative/path', 'foo', '../x'] as $junk) {
        $r = scenario(['content' => $t . '/wp-content', 'site_options' => ['fluxfiles_storage_base' => $junk]], $probe);
        assertEqual($t . '/wp-content/uploads/fluxfiles', $r['path'], $junk);
        assertEqual('uploads', $r['opt'], 'replaced by a token');
    }
    rrm($t);
});

test('a stream-wrapper uploads basedir (s3://...) selects the content location', function () use ($probe) {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content', 'stream_uploads' => true], $probe);
    assertEqual($t . '/wp-content/fluxfiles', $r['path']);
    assertEqual('content', $r['opt']);
    rrm($t);
});

test('no directories are created while on the no-uploads-API fallback', function () {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content', 'no_upload_api' => true],
        '$fb = FluxFilesStorage::onFallback(); $p = FluxFilesPlugin::storagePath(); try { (new ReflectionClass(FluxFilesPlugin::class))->newInstanceWithoutConstructor()->activate(); } catch (\\Throwable $e) { /* the DB install step needs real WP */ } fx_out(["fb" => $fb, "dir" => is_dir($p)]);', true);
    assertTrue($r['fb'] === true && $r['dir'] === false, 'fallback: no mkdir: ' . json_encode($r));
    rrm($t);
});

test('the Pro plugin\'s options / transient / user meta are never touched by uninstall.php', function () use ($uninstallFile) {
    $src = (string) file_get_contents($uninstallFile);
    foreach (['fluxfiles_license_key', 'fluxfiles_module_env', 'fluxfiles_update_url', 'fluxfiles_update_manifest',
        'fluxfiles_license_notice_dismissed', 'delete_user_meta', 'delete_transient', 'delete_site_transient'] as $name) {
        assertTrue(strpos($src, $name) === false, "{$name} must not appear in uninstall.php");
    }
});

echo "\n  " . ($failed === 0 ? $green : $red) . "{$passed} passed, {$failed} failed{$reset}\n\n";
exit($failed === 0 ? 0 : 1);
