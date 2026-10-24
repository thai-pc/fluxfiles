<?php
// php -S router for WordPress: real files (static or .php) are served by the
// built-in server; everything else (pretty permalinks, /wp-json/…) goes to index.php.
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = '/var/www/html' . $path;
if ($path !== '/' && (is_file($file) || (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')))) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
chdir('/var/www/html');
require '/var/www/html/index.php';
