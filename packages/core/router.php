<?php

/**
 * PHP built-in server router.
 *
 * Usage: php -S localhost:8000 router.php
 *
 * Routes /public/index.html through api/index.php so the locale
 * can be injected server-side (no flash of untranslated content).
 * All other static files (CSS, JS, images) are served directly.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve favicon as inline SVG
if ($uri === '/favicon.ico') {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=604800');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="6" fill="#4F46E5"/><path d="M8 10a2 2 0 012-2h4l2 2h6a2 2 0 012 2v10a2 2 0 01-2 2H10a2 2 0 01-2-2V10z" fill="white" fill-opacity="0.9"/><path d="M12 17l2.5-3 2 2 3.5-4" stroke="#4F46E5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
    return true;
}

// Route /public/index.html (and /public/) through the API for locale injection
if ($uri === '/public/index.html' || $uri === '/public/' || $uri === '/public') {
    require __DIR__ . '/api/index.php';
    return true;
}

// Route /api/* through the API
if (strncmp($uri, '/api/', 5) === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Serve uploaded files from /storage/uploads/
if (strncmp($uri, '/storage/uploads/', 17) === 0) {
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg',
            'txt' => 'text/plain', 'doc' => 'application/msword', 'zip' => 'application/zip',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? mime_content_type($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        return true;
    }
}

// Serve static files normally
return false;
