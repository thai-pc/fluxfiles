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
define('FLUXFILES_PLUGIN_FILE', 'x');
define('FLUXFILES_VERSION', '1.0.0');
if (!empty($FXT['const'])) { define('FLUXFILES_STORAGE_DIR', $FXT['const']); }
if (!empty($FXT['uninstall'])) { define('WP_UNINSTALL_PLUGIN', 'fluxfiles/fluxfiles.php'); }
$GLOBALS['CUR'] = 1;
$GLOBALS['OPT'] = $FXT['options'] ?? [];      // blog id => [name => value]
$GLOBALS['SOPT'] = $FXT['site_options'] ?? []; // network options
$GLOBALS['LOG'] = ['delete_option' => [], 'delete_site_option' => [], 'queries' => [], 'switch' => [], 'registered' => [], 'sites_calls' => 0];
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
function switch_to_blog($id) { $GLOBALS['STACK'][] = $GLOBALS['CUR']; $GLOBALS['CUR'] = (int) $id; $GLOBALS['LOG']['switch'][] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['CUR'] = array_pop($GLOBALS['STACK']) ?? 1; return true; }
function get_sites($args = []) {
    global $FXT;
    $GLOBALS['LOG']['sites_calls']++;
    $all = range(1, (int) ($FXT['site_count'] ?? 1));
    return array_slice($all, (int) ($args['offset'] ?? 0), (int) ($args['number'] ?? 100) ?: null);
}
if (empty($FXT['no_upload_api'])) {
    function wp_upload_dir($t = null, $c = true) {
        global $FXT;
        $sub = $GLOBALS['CUR'] === 1 ? '' : '/sites/' . $GLOBALS['CUR'];   // WP gives sub-sites uploads/sites/N
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
    assertEqual($t . '/wp-content/fluxfiles', $r['opt'], 'decision stored');
    assertTrue($r['disk']['private'] === false, 'public url => not private by default');
    rrm($t);
});

test('fresh install lands in uploads/fluxfiles, url under the uploads baseurl', function () use ($probe) {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content'], $probe);
    assertEqual($t . '/wp-content/uploads/fluxfiles', $r['path']);
    assertEqual('http://wp.test/wp-content/uploads/fluxfiles/uploads', $r['disk']['url']);
    assertEqual('fluxfiles/uploads', $r['rel'], 'relative to the uploads dir');
    assertEqual($t . '/wp-content/uploads/fluxfiles', $r['opt']);
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
    $r = scenario(['content' => $t . '/wp-content', 'site_options' => ['fluxfiles_storage_base' => $t . '/wp-content/uploads/fluxfiles']], $probe);
    assertEqual($t . '/wp-content/uploads/fluxfiles', $r['path']);
    rrm($t);
});

test('override order: constant > filter > option', function () use ($probe) {
    $t = tmpTree();
    $opt = ['fluxfiles_storage_base' => $t . '/wp-content/uploads/fluxfiles'];
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

test('without the uploads API the legacy wp-content path is used and nothing is persisted', function () use ($probe) {
    $t = tmpTree();
    $r = scenario(['content' => $t . '/wp-content', 'no_upload_api' => true], $probe);
    assertEqual($t . '/wp-content/fluxfiles', $r['path']);
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

test('every option registered with register_setting is deleted, plus secret / storage_base / db version', function () use ($runUninstall) {
    $t = tmpTree();
    $cfg = ['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $t . '/base'];
    $reg = scenario($cfg, 'require_once ' . var_export(dirname(__DIR__) . '/includes/FluxFilesAdmin.php', true) . '; (new FluxFilesAdmin())->registerSettings(); fx_out($GLOBALS["LOG"]["registered"]);');
    assertTrue(count($reg) >= 20, 'registered set looks complete: ' . count($reg));
    assertTrue(in_array('fluxfiles_delete_data_on_uninstall', $reg, true), 'opt-in option is a registered setting');
    $u = scenario($cfg, $runUninstall);
    $deleted = $u['delete_option'][1] ?? [];
    $missing = array_values(array_diff($reg, $deleted));
    assertEqual([], $missing, 'registered but not deleted');
    foreach (['fluxfiles_secret', 'fluxfiles_storage_base', 'fluxfiles_db_version'] as $name) {
        assertTrue(in_array($name, $deleted, true), "{$name} deleted");
    }
    assertTrue(in_array('fluxfiles_storage_base', $u['delete_site_option'], true), 'site-level storage base deleted');
    rrm($t);
});

test('every get_option()/update_option() name used by the plugin code is deleted too', function () use ($pluginDir, $runUninstall) {
    $used = [];
    foreach (glob($pluginDir . '/includes/*.php') ?: [] as $f) {
        if (preg_match_all("/(?:get|update|add)_(?:site_)?option\(\s*'(fluxfiles_[a-z0-9_]+)'/", (string) file_get_contents($f), $m)) {
            $used = array_merge($used, $m[1]);
        }
    }
    $t = tmpTree();
    $u = scenario(['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $t . '/base'], $runUninstall);
    $missing = array_values(array_diff(array_unique($used), $u['delete_option'][1] ?? []));
    assertEqual([], $missing, 'used but not deleted');
    rrm($t);
});

test('the DB tables are dropped and the usage transients cleared', function () use ($runUninstall) {
    $t = tmpTree();
    $u = scenario(['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $t . '/base'], $runUninstall);
    $sql = implode("\n", array_column($u['queries'], 'sql'));
    foreach (['file_metadata', 'directories', 'trash', 'audit_log', 'audit_chain_head', 'legal_holds', 'rate_limits'] as $tbl) {
        assertTrue(strpos($sql, 'DROP TABLE IF EXISTS `wp_fluxfiles_' . $tbl . '`') !== false, "drops {$tbl}");
    }
    assertTrue(strpos(str_replace('\\', '', $sql), '_transient_fluxfiles_usage_') !== false, 'usage transients removed');
    rrm($t);
});

test('stored files are kept unless fluxfiles_delete_data_on_uninstall is on', function () use ($runUninstall) {
    foreach ([[null, true], ['0', true], ['1', false]] as [$flag, $kept]) {
        $t = tmpTree();
        $base = $t . '/base';
        mkdir($base . '/uploads/sub', 0777, true);
        file_put_contents($base . '/uploads/sub/photo.jpg', 'bytes');
        file_put_contents($base . '/uploads/.htaccess', 'x');
        file_put_contents($base . '/index.php', 'x');
        file_put_contents($base . '/keep-me.txt', 'unrelated');   // a custom base is never wiped wholesale
        $opts = $flag === null ? [] : [1 => ['fluxfiles_delete_data_on_uninstall' => $flag]];
        scenario(['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $base, 'options' => $opts], $runUninstall);
        assertEqual($kept, is_file($base . '/uploads/sub/photo.jpg'), 'flag ' . var_export($flag, true));
        assertTrue(is_file($base . '/keep-me.txt'), 'unrelated files in a custom base survive');
        if (!$kept) {
            assertTrue(!is_dir($base . '/uploads') && !is_file($base . '/index.php'), 'plugin-owned state removed');
        }
        rrm($t);
    }
});

test('multisite: every site is visited in batches, per-site opt-in decides its files, network options go', function () use ($runUninstall) {
    $t = tmpTree();
    $base = $t . '/base';
    foreach ([1, 2, 3] as $b) {
        mkdir($base . "/sites/{$b}/uploads", 0777, true);
        file_put_contents($base . "/sites/{$b}/uploads/f.txt", 'x');
    }
    $cfg = ['content' => $t . '/wp-content', 'uninstall' => true, 'const' => $base, 'multisite' => true, 'site_count' => 250,
        'options' => [2 => ['fluxfiles_delete_data_on_uninstall' => '1']],
        'site_options' => ['fluxfiles_storage_base' => $base]];
    $u = scenario($cfg, $runUninstall);
    assertEqual(range(1, 250), $u['switch'], 'all 250 sites visited');
    assertEqual(3, $u['sites_calls'], 'get_sites paged (100/100/50, never a single unbounded call)');
    assertTrue(in_array('fluxfiles_secret', $u['delete_option'][250] ?? [], true), 'last site cleaned');
    $dropped = array_filter($u['queries'], fn ($q) => str_contains($q['sql'], 'DROP TABLE') && $q['blog'] === 2);
    assertEqual(7, count($dropped), 'tables dropped per site');
    assertTrue(!is_dir($base . '/sites/2/uploads'), 'opted-in site 2 files removed');
    assertTrue(is_file($base . '/sites/1/uploads/f.txt') && is_file($base . '/sites/3/uploads/f.txt'), 'other sites keep their files');
    assertTrue(in_array('fluxfiles_storage_base', $u['delete_site_option'], true), 'network option deleted');
    assertTrue(!isset($u['sopt']['fluxfiles_storage_base']), 'gone from the network options');
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
