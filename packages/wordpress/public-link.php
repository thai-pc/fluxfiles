<?php

/**
 * Serves the bundled recipient pages (share.html / intake.html) to someone who has a
 * link, with the API base pointed at this site's REST namespace.
 *
 * The pages themselves are static and shipped inside the plugin, but a static file
 * cannot know where the API lives: standalone answers at `/api/fm/…` from the site
 * root, WordPress at `/wp-json/fluxfiles/v1/api/fm/…`. So they are served through this
 * shim, which injects `window.__FM_API_BASE__` and changes nothing else.
 *
 * It loads WordPress because it needs `rest_url()` and the plugin's own paths — but it
 * is reached by a RECIPIENT with no account, so it must never assume a logged-in user
 * or a nonce. Everything that decides whether the link works (signature, expiry,
 * password, download cap, revocation) happens later, inside the REST routes the page
 * calls.
 */

declare(strict_types=1);

// Boot WordPress. This file sits in wp-content/plugins/<dir>/, so wp-load.php is four
// levels up; the loop tolerates a non-standard layout rather than hardcoding it.
$wpLoad = null;
for ($dir = __DIR__, $i = 0; $i < 6; $i++, $dir = dirname($dir)) {
    if (is_file($dir . '/wp-load.php')) {
        $wpLoad = $dir . '/wp-load.php';
        break;
    }
}
if ($wpLoad === null) {
    http_response_code(500);
    exit('WordPress not found');
}
require_once $wpLoad;

// Only the two known pages, matched exactly. This value reaches a filesystem read, so
// it is compared against a whitelist rather than sanitised — a sanitiser is a filter
// someone eventually finds a way through.
$pages = ['share' => 'share.html', 'intake' => 'intake.html'];
$which = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
if (!isset($pages[$which])) {
    status_header(404);
    exit('Not found');
}

$publicDir = class_exists('FluxFilesPlugin') ? FluxFilesPlugin::corePath('public') : null;
$file = $publicDir === null ? null : $publicDir . '/' . $pages[$which];
if ($file === null || !is_file($file)) {
    status_header(500);
    exit('The FluxFiles core is not available');
}

$html = (string) file_get_contents($file);

// The REST base the page should call. rest_url() honours whatever permalink structure
// the site uses, including the ?rest_route= form on plain permalinks.
$apiBase = rtrim(rest_url('fluxfiles/v1/api/fm'), '/');
$inject = '<script>window.__FM_API_BASE__=' . wp_json_encode($apiBase) . ';</script>';

// Ahead of the page's own script, which reads the value on load. Falls back to
// prepending so a page without <head> still works rather than silently losing the base.
$html = strpos($html, '</head>') !== false
    ? str_replace('</head>', $inject . '</head>', $html)
    : $inject . $html;

// Same headers standalone sends: the URL carries the token, so it must not leak in a
// Referer, and nothing here should be cached by a shared proxy.
header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $html;
