<?php

/**
 * Local HTTP fixture for the URL-import fetch integration test. Run with:
 *   php -S 127.0.0.1:<port> tests/integration/fixtures/import-fixture.php
 *
 * Routes (see test-url-import-fetch.php):
 *   /png               200 image/png + Content-Disposition filename
 *   /redirect-ok       302 → /png
 *   /redirect-private  302 → http://169.254.169.254/  (per-hop SSRF block)
 *   /loop              302 → /loop  (redirect loop)
 *   /notfound          404
 *   /forbidden         403
 *   /big               200 with a body larger than the test's size cap
 *   /html-as-png       200 image/png header but an HTML body (magic-byte deny)
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// A tiny but real PNG with a random pixel per request, so each fetch produces
// unique bytes (the upload pipeline's dedup would otherwise collapse two imports).
function fixturePng(): string
{
    $im = imagecreatetruecolor(8, 8);
    imagesetpixel($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    return (string) ob_get_clean();
}

switch ($path) {
    case '/png':
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="sunset.png"');
        echo fixturePng();
        break;

    case '/redirect-ok':
        header('Location: /png', true, 302);
        break;

    case '/redirect-private':
        header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
        break;

    case '/loop':
        header('Location: /loop', true, 302);
        break;

    case '/notfound':
        http_response_code(404);
        echo 'not found';
        break;

    case '/forbidden':
        http_response_code(403);
        echo 'forbidden';
        break;

    case '/big':
        header('Content-Type: application/octet-stream');
        echo str_repeat('A', 200000); // 200 KB — exceeds the test's tiny cap
        break;

    case '/html-as-png':
        header('Content-Type: image/png'); // lies — body is HTML
        echo "<!doctype html><html><body><script>alert(1)</script></body></html>";
        break;

    default:
        http_response_code(404);
        echo 'unknown route';
}
