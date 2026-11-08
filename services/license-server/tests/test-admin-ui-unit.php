<?php

declare(strict_types=1);

require_once __DIR__ . '/support/Harness.php';
require_once dirname(__DIR__) . '/admin/src/View.php';
require_once dirname(__DIR__) . '/admin/src/Auth.php';
require_once dirname(__DIR__) . '/admin/src/Csrf.php';
require_once dirname(__DIR__) . '/ops/src/Telegram.php';

use FluxFiles\LicenseServer\Admin\{Totp, Auth, Csrf, View, Svg};
use FluxFiles\LicenseServer\Client\{Mask, Config};
use FluxFiles\LicenseServer\Ops\Telegram;

$h = new Harness();
$h->test('RFC 6238 SHA1 appendix B and six-digit truncation', function (): void {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    foreach (
        [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ]
        as $ts => $code
    ) {
        Harness::eq($code, Totp::code($secret, intdiv($ts, 30), 8));
        Harness::eq(substr($code, -6), Totp::code($secret, intdiv($ts, 30)));
    }
    foreach ([-1, 0, 1] as $delta) {
        Harness::eq(100 + $delta, Totp::verify($secret, Totp::code($secret, 100 + $delta), 3000));
    }
    Harness::eq(null, Totp::verify($secret, Totp::code($secret, 100), 3000, 100));
    Harness::eq(null, Totp::verify($secret, '12345', 3000));
});
$h->test('Host, Origin, token and Sec-Fetch-Site checks', function (): void {
    foreach (['localhost:8471', '127.0.0.1:9472'] as $host) {
        Harness::ok(Csrf::host($host, 'localhost,127.0.0.1'));
    }
    foreach (
        [
            'evil.example:8471',
            'localhost.evil',
            'localhost:0',
            'localhost:99999',
            'localhost@evil',
            'localhost/',
        ]
        as $host
    ) {
        Harness::ok(!Csrf::host($host, 'localhost,127.0.0.1'));
    }
    $server = ['HTTP_HOST' => 'localhost:8471', 'HTTP_ORIGIN' => 'http://localhost:8471'];
    Harness::ok(Csrf::check($server, 'a', 'a'));
    foreach (
        [
            ['HTTP_ORIGIN' => 'http://evil'],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            ['HTTP_ORIGIN' => ''],
        ]
        as $bad
    ) {
        Harness::ok(!Csrf::check($bad + $server, 'a', 'a'));
    }
    Harness::ok(!Csrf::check($server, 'a', 'b'));
});
$h->test('Mask helpers refuse key and raw email, redact arbitrary data', function (): void {
    $key = str_repeat('a', 20) . '.' . str_repeat('b', 20) . '.' . str_repeat('c', 20);
    Harness::eq('a***@example.com', Mask::email('alice@example.com'));
    Harness::eq('abcdef…uvwxyz', Mask::id('abcdefghijklmnopqrstuvwxyz'));
    foreach (
        [
            $key,
            'alice@example.com',
            'alice@example.com;a***@domain.test',
            'a***@domain.test,alice@example.com',
        ]
        as $text
    ) {
        try {
            Mask::safe($text, true);
            throw new RuntimeException('accepted');
        } catch (InvalidArgumentException $e) {
            Harness::eq('masking', $e->getMessage());
        }
    }
    Harness::eq('a***@example.com', Mask::safe('a***@example.com', true));
    Harness::ok(!str_contains(Mask::clean($key . ' alice@example.com bot123:SECRET'), $key));
    Harness::ok(!isset(Mask::row(['license_key' => $key])['license_key']));
});
$h->test('Currency exponents, escaping and SVG table parity', function (): void {
    Harness::eq('12.34 USD', View::money(1234, 'usd'));
    Harness::eq('1,234 VND', View::money(1234, 'vnd'));
    Harness::eq('-1.00 EUR', View::money(-100, 'eur'));
    Harness::eq('&lt;&quot;&#039;&amp;', View::h('<"\'&'));
    $svg = Svg::bar(
        [['label' => '<img src=x onerror=1>', 'n' => 21]],
        'label',
        'n',
        '<script>',
        'Table',
    );
    Harness::ok(str_contains($svg, '&lt;img'));
    Harness::ok(preg_match('~<td[^>]*>21</td>~', $svg) === 1);
    Harness::ok(!str_contains($svg, 'style='));
    Harness::ok(!str_contains($svg, '<script>'));
    Harness::ok(str_contains($svg, 'aria-labelledby'));
    $negative = Svg::bar(
        [['label' => 'positive', 'n' => 10], ['label' => 'negative', 'n' => -10]],
        'label',
        'n',
        'Net',
        'Table',
    );
    preg_match_all(
        '/<rect[^>]* y="([0-9.]+)"[^>]*height="([0-9.]+)"/',
        $negative,
        $bars,
        PREG_SET_ORDER,
    );
    Harness::eq(2, count($bars));
    Harness::ok(
        (float) $bars[1][1] > (float) $bars[0][1],
        'negative bar must start below positive',
    );
});
$h->test('i18n parity and all literal translation keys exist', function (): void {
    $base = dirname(__DIR__) . '/admin';
    $en = require "$base/lang/en.php";
    $vi = require "$base/lang/vi.php";
    Harness::eq(array_keys($en), array_keys($vi));
    foreach (glob("$base/templates/*.php") as $file) {
        preg_match_all("/->t\\('([^']+)'\\)/", file_get_contents($file), $m);
        foreach ($m[1] as $key) {
            Harness::ok(isset($en[$key]), $key);
        }
    }
});
$h->test('No inline code or unsafe DOM sinks; isolated source imports', function (): void {
    foreach (['admin', 'ops', 'client'] as $dir) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                dirname(__DIR__) . '/' . $dir,
                FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($it as $file) {
            $text = file_get_contents($file->getPathname());
            Harness::ok(
                !preg_match(
                    '/LicenseSigner|LicenseStore|LicenseIssuer|PRIVATE_KEY|licenses\.sqlite/',
                    $text,
                ),
                $file->getFilename(),
            );
            if (
                $dir === 'admin' &&
                (str_contains($file->getPathname(), '/templates/') ||
                    str_contains($file->getPathname(), '/public/'))
            ) {
                Harness::ok(
                    !preg_match(
                        '/<script\\b(?![^>]*\\bsrc=)|<style\\b|\\bstyle\\s*=|\\bon[a-z]+\\s*=|javascript:|innerHTML/i',
                        $text,
                    ),
                    $file->getFilename(),
                );
            }
        }
    }
});
$h->test('Loopback credential destinations fail closed', function (): void {
    Harness::eq('http://127.0.0.1:1', Config::loopback('http://127.0.0.1:1'));
    foreach (
        [
            'https://evil',
            'http://localhost',
            'http://127.0.0.1@evil',
            'http://127.0.0.1?x=1',
            'http://127.0.0.1\\evil',
        ]
        as $url
    ) {
        try {
            Config::loopback($url);
            throw new RuntimeException('accepted');
        } catch (InvalidArgumentException $e) {
        }
    }
});
$h->test('Sandbox defaults to Telegram off and a distinct tag', function (): void {
    putenv('FLUXFILES_LICENSE_INSTANCE=sandbox');
    $sbx = new Telegram();
    Harness::ok(!$sbx->enabled());
    Harness::eq('FluxFiles SANDBOX', $sbx->tag);
    putenv('FLUXFILES_LICENSE_INSTANCE');
    Harness::eq('FluxFiles', (new Telegram())->tag);
});
$h->finish();
