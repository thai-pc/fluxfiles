<?php

/**
 * RequestInput: validated REMOTE_ADDR / User-Agent / ?lang reads.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\I18n;
use FluxFiles\RequestInput;

$fail = 0;
$check = function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . " $name\n";
    if (!$ok) {
        $fail++;
    }
};

$check('ipv4 kept', RequestInput::ip('203.0.113.7') === '203.0.113.7');
$check('ipv6 kept', RequestInput::ip('2001:db8::1') === '2001:db8::1');
$check('garbage ip dropped', RequestInput::ip("1.2.3.4\r\nX: y") === '');
$check('html ip dropped', RequestInput::ip('<script>') === '');
$check('array ip dropped', RequestInput::ip(['1.2.3.4']) === '');

$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
$check('remoteIp invalid -> empty', RequestInput::remoteIp() === '');
$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
$check('remoteIp valid', RequestInput::remoteIp() === '198.51.100.2');
unset($_SERVER['REMOTE_ADDR']);
$check('remoteIp absent', RequestInput::remoteIp() === '');

$check('ua control chars stripped', RequestInput::cleanUserAgent("Mozilla\r\n\x00/5.0") === 'Mozilla /5.0');
$check('ua array dropped', RequestInput::cleanUserAgent(['x']) === '');
$check('ua capped', strlen(RequestInput::cleanUserAgent(str_repeat('a', 5000))) === 512);

$_GET['lang'] = ['vi'];
$check('array ?lang falls back to en', (new I18n(__DIR__ . '/../../lang'))->locale() === 'en');
$_GET['lang'] = 'vi';
$check('string ?lang honoured', (new I18n(__DIR__ . '/../../lang'))->locale() === 'vi');
unset($_GET['lang']);

exit($fail ? 1 : 0);
