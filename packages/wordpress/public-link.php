<?php

/**
 * Serves the bundled recipient pages (share.html / intake.html) to someone who has a
 * link, with the API base pointed at this site's REST namespace.
 *
 * The pages themselves are static and shipped by the Share/Intake modules, but a static
 * file cannot know where the API lives: standalone answers at `/api/fm/…` from the site
 * root, WordPress at `/wp-json/fluxfiles/v1/api/fm/…`. So they are served through this
 * shim, which asks core's PublicRoutes::page() to inject the API base and changes nothing else.
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

// Only the two known pages, matched exactly (core's PublicRoutes also whitelists the id).
$pages = ['share' => true, 'intake' => true];
$which = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
if (!isset($pages[$which])) {
    status_header(404);
    exit('Not found');
}

if (!class_exists('FluxFilesPlugin') || !class_exists('FluxFiles\\PublicRoutes')) {
    status_header(500);
    exit('The FluxFiles core is not available');
}

// The REST base the page should call. rest_url() honours whatever permalink structure
// the site uses, including the ?rest_route= form on plain permalinks.
// On multisite the shim boots whichever blog the plugin URL maps to, so the API base is
// resolved from the link's `blog` id to the site that minted it. The REST route there
// verifies the token against that site's own secret and storage: an id that was
// forged or swapped simply points the page at a site whose signature check fails.
$blogParam = $_GET['blog'] ?? null; // a non-string (e.g. blog[]=) is refused below
$apiBase = FluxFilesPlugin::publicLinkRestBase($blogParam);
if ($apiBase === null) {
    status_header(404);
    exit('Not found');
}

// Multisite: the link's blog owns the secret, licence and frame-ancestors, so read them
// from THAT site (the id was validated above), not from whichever blog booted.
$switched = false;
if (function_exists('is_multisite') && is_multisite() && function_exists('switch_to_blog')) {
    $blogId = ($blogParam === null || $blogParam === '') ? (int) get_current_blog_id() : (int) $blogParam;
    if ($blogId !== (int) get_current_blog_id()) {
        switch_to_blog($blogId);
        $switched = true;
    }
}
try {
    // The same gate and headers as the REST `<module>/page` routes: one shared helper.
    FluxFilesPlugin::servePublicPage($which, $apiBase);
} finally {
    if ($switched) {
        restore_current_blog();
    }
}
