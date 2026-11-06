<?php
// Legacy recipient-link shim. Links minted before the REST page routes
// (/wp-json/fluxfiles/v1/api/fm/<share|intake>/page) pointed here. It deliberately loads
// nothing, not even WordPress: it only redirects to the REST page route, which does the
// real work. The site root is dirname(path, 4) of /wp-content/plugins/<dir>/public-link.php
// (also right for a subdirectory install). The redirect is relative: no host is trusted.
// TODO(phase 8): delete once old links have expired
$which = $_GET['page'] ?? '';
if ($which !== 'share' && $which !== 'intake') {
    http_response_code(404);
    exit('Not found');
}
$root = rtrim(dirname(parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH) ?: '/', 4), '/');
$to = $root . '/?rest_route=/fluxfiles/v1/api/fm/' . $which . '/page';
$token = $_GET['token'] ?? '';
if (is_string($token) && $token !== '') {
    $to .= '&token=' . rawurlencode($token);
}
$blog = $_GET['blog'] ?? '';
if (is_string($blog) && preg_match('/^[0-9]+\z/', $blog) === 1) {
    $to .= '&blog=' . rawurlencode($blog);
}
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Location: ' . $to, true, 302);
exit;
