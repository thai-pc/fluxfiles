<?php

/**
 * Core mail layer for e-mail notices (docs/design/INTAKE-SHARE-NOTIFICATIONS-DESIGN.md §6, §8):
 * MailMessage validation + MIME, every provider transport against a fake HTTP layer (endpoint,
 * auth header, payload shape, no credential in an error), SMTP against a scripted socket, and
 * the env factory matrix.
 *
 * Usage: php tests/unit/test-mail.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\HttpTransportInterface;
use FluxFiles\Log;
use FluxFiles\Mail\LogTransport;
use FluxFiles\Mail\MailException;
use FluxFiles\Mail\MailgunTransport;
use FluxFiles\Mail\MailMessage;
use FluxFiles\Mail\MailTransportFactory;
use FluxFiles\Mail\PostmarkTransport;
use FluxFiles\Mail\ResendTransport;
use FluxFiles\Mail\SesTransport;
use FluxFiles\Mail\SmtpTransport;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    try { $f(); echo "  \033[32mPASS\033[0m {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  \033[31mFAIL\033[0m {$n}: " . get_class($e) . ': ' . $e->getMessage() . "\n"; $failed++; }
}
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new \RuntimeException($m); }
function eq($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function throws(callable $f, string $class = \Throwable::class): \Throwable {
    try { $f(); } catch (\Throwable $e) { if ($e instanceof $class) { return $e; } throw new \RuntimeException('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage()); }
    throw new \RuntimeException('expected ' . $class . ', nothing thrown');
}

Log::setSink(static function (string $m): void {});   // keep the factory's warnings off the console

final class FakeHttp implements HttpTransportInterface
{
    /** @var list<array{method:string,url:string,headers:array,body:?string}> */
    public array $calls = [];
    public function __construct(public int $status = 200, public string $body = '{"id":"x"}', public string $error = '') {}
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return $this->status === 0
            ? ['status' => 0, 'headers' => [], 'body' => '', 'error' => $this->error]
            : ['status' => $this->status, 'headers' => [], 'body' => $this->body];
    }
}

$msg = static fn (): MailMessage => new MailMessage(['Ops@Acme.com', 'b@acme.com'], 'Hello “there”', "line 1\n.\nline 3", 'Acme Ltd', 'reply@acme.com');

echo "\n\033[36m══ MailMessage ══\033[0m\n";

test('recipients are validated, lower-cased and de-duplicated', function () {
    $m = new MailMessage(['A@x.io', 'a@x.io', 'b@x.io'], 's', 't');
    eq(['a@x.io', 'b@x.io'], $m->to);
});
test('CR/LF in subject, display name or an address is refused', function () {
    throws(fn () => new MailMessage(['a@x.io'], "s\r\nBcc: v@evil.io", 't'), \InvalidArgumentException::class);
    throws(fn () => new MailMessage(['a@x.io'], 's', 't', "N\nBcc: v@evil.io"), \InvalidArgumentException::class);
    throws(fn () => new MailMessage(["a@x.io\r\nBcc: v@evil.io"], 's', 't'), \InvalidArgumentException::class);
    throws(fn () => new MailMessage(['a@x.io'], 's', 't', '', "r@x.io\nBcc: v@evil.io"), \InvalidArgumentException::class);
});
test('no recipients / too many / invalid UTF-8 refused', function () {
    throws(fn () => new MailMessage([], 's', 't'), \InvalidArgumentException::class);
    throws(fn () => new MailMessage(array_map(fn ($i) => "u{$i}@x.io", range(1, 11)), 's', 't'), \InvalidArgumentException::class);
    throws(fn () => new MailMessage(['a@x.io'], "s\xC3\x28", 't'), \InvalidArgumentException::class);
});
test('validAddress rejects junk', function () {
    foreach (['', 'nope', 'a@', '@x.io', 'a b@x.io', '<a@x.io>', str_repeat('a', 250) . '@x.io'] as $bad) {
        eq(null, MailMessage::validAddress($bad), $bad);
    }
    eq('a@x.io', MailMessage::validAddress('  A@X.io '));
});
test('address literals are refused (no mail routed at an internal host)', function () {
    foreach (['a@[127.0.0.1]', 'a@[10.0.0.5]', 'a@[IPv6:::1]', 'a@localhost', 'a@-x.io'] as $bad) {
        eq(null, MailMessage::validAddress($bad), $bad);
    }
    eq('a@mail.acme.co.uk', MailMessage::validAddress('a@mail.acme.co.uk'));
});
test('HTTP-API From never carries a CRLF fold; SMTP header does', function () {
    $long = str_repeat('Тестовая компания ', 4);
    ok(!str_contains(MailMessage::mailbox('n@acme.com', $long, false), "\r\n"), 'unfolded for APIs');
    ok(str_contains(MailMessage::mailbox('n@acme.com', $long), "\r\n "), 'folded for SMTP');
    $h = new FakeHttp();
    (new ResendTransport('k', 'n@acme.com', $long, $h))->send(new MailMessage(['a@acme.com'], 's', 't'));
    ok(!str_contains((string) json_decode((string) $h->calls[0]['body'], true)['from'], "\r"), 'resend from');
});
test('a line over 998 octets switches the SMTP body to quoted-printable', function () {
    $mime = (new MailMessage(['a@acme.com'], 's', "open: https://app.acme.com/?p=" . str_repeat('x', 2000) . "\n."))->toMime('n@acme.com', '');
    ok(str_contains($mime, 'Content-Transfer-Encoding: quoted-printable'), 'qp header');
    foreach (explode("\r\n", $mime) as $line) {
        ok(strlen($line) <= 998, 'line too long: ' . strlen($line));
    }
    ok(str_contains((new MailMessage(['a@acme.com'], 's', 'short'))->toMime('n@acme.com', ''), 'Content-Transfer-Encoding: 8bit'), 'short stays 8bit');
});
test('maskAddress / cleanLabel', function () {
    eq('j•••@acme.com', MailMessage::maskAddress('jane@acme.com'));
    eq('a b', MailMessage::cleanLabel("a\x00\n\tb"));
    eq('evilgood', str_replace(' ', '', MailMessage::cleanLabel("evil\u{202E}good")));   // bidi override stripped
    eq(10, mb_strlen(MailMessage::cleanLabel(str_repeat('x', 50), 10)));
});
test('toMime: RFC 2047 subject, dot-stuffed CRLF body, auto-submitted headers', function () use ($msg) {
    $mime = $msg()->toMime('noreply@acme.com', 'Fallback');
    ok(str_contains($mime, "Subject: =?UTF-8?B?"), 'subject encoded');
    ok(str_contains($mime, 'From: "Acme Ltd" <noreply@acme.com>'), 'from');
    ok(str_contains($mime, "To: ops@acme.com, b@acme.com"), 'to');
    ok(str_contains($mime, "Reply-To: reply@acme.com"), 'reply-to');
    ok(str_contains($mime, "Auto-Submitted: auto-generated"), 'auto-submitted');
    ok(str_contains($mime, "X-Auto-Response-Suppress: All"), 'suppress');
    ok(str_contains($mime, "\r\n\r\nline 1\r\n..\r\nline 3"), 'dot-stuffed body');
    ok(!str_contains(substr($mime, strpos($mime, "\r\n\r\n") + 4), "\n.\n"), 'no bare dot line');
});
test('encodeHeader keeps every encoded word ≤ 75 chars', function () {
    $enc = MailMessage::encodeHeader(str_repeat('Ünïcödé ', 30));
    foreach (explode("\r\n ", $enc) as $w) {
        ok(strlen($w) <= 75, 'word too long: ' . strlen($w));
    }
});

echo "\n\033[36m══ HTTP provider transports ══\033[0m\n";

test('Resend: endpoint, Bearer key, JSON shape', function () use ($msg) {
    $h = new FakeHttp();
    (new ResendTransport('re_SECRETKEY_123', 'noreply@acme.com', 'Fallback', $h))->send($msg());
    $c = $h->calls[0];
    eq('https://api.resend.com/emails', $c['url']);
    eq('Bearer re_SECRETKEY_123', $c['headers']['Authorization']);
    $p = json_decode((string) $c['body'], true);
    eq(['ops@acme.com', 'b@acme.com'], $p['to']);
    eq('"Acme Ltd" <noreply@acme.com>', $p['from']);
    eq('reply@acme.com', $p['reply_to']);
    eq('auto-generated', $p['headers']['Auto-Submitted']);
});
test('Postmark: token header, comma recipients, stream, headers list', function () use ($msg) {
    $h = new FakeHttp();
    (new PostmarkTransport('pm-token', 'outbound', 'noreply@acme.com', '', $h))->send($msg());
    $c = $h->calls[0];
    eq('https://api.postmarkapp.com/email', $c['url']);
    eq('pm-token', $c['headers']['X-Postmark-Server-Token']);
    $p = json_decode((string) $c['body'], true);
    eq('ops@acme.com,b@acme.com', $p['To']);
    eq('outbound', $p['MessageStream']);
    eq('Auto-Submitted', $p['Headers'][0]['Name']);
});
test('Mailgun: region host, Basic auth, form fields incl. every recipient', function () use ($msg) {
    $h = new FakeHttp();
    (new MailgunTransport('key-abc', 'mg.acme.com', 'eu', 'noreply@acme.com', '', $h))->send($msg());
    $c = $h->calls[0];
    eq('https://api.eu.mailgun.net/v3/mg.acme.com/messages', $c['url']);
    eq('Basic ' . base64_encode('api:key-abc'), $c['headers']['Authorization']);
    parse_str(str_replace('to=', 'to[]=', (string) $c['body']), $f);
    eq(['ops@acme.com', 'b@acme.com'], $f['to']);
    eq('auto-generated', $f['h:Auto-Submitted']);
    throws(fn () => new MailgunTransport('k', 'evil.io/x?', 'us', 'n@a.io', '', $h), \InvalidArgumentException::class);
});
test('SES: SigV4-signed POST to the regional v2 endpoint', function () use ($msg) {
    $h = new FakeHttp();
    $creds = fn () => new \Aws\Credentials\Credentials('AKIDEXAMPLE', 'SECRETEXAMPLEKEY');
    (new SesTransport('eu-west-1', 'noreply@acme.com', '', $h, $creds))->send($msg());
    $c = $h->calls[0];
    eq('https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', $c['url']);
    ok(str_starts_with($c['headers']['Authorization'] ?? '', 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/'), 'sigv4 header');
    ok(str_contains($c['headers']['Authorization'], '/eu-west-1/ses/aws4_request'), 'scope');
    $p = json_decode((string) $c['body'], true);
    eq(['ops@acme.com', 'b@acme.com'], $p['Destination']['ToAddresses']);
    eq(['reply@acme.com'], $p['ReplyToAddresses']);
    throws(fn () => new SesTransport('eu-west-1.evil.io', 'n@a.io', '', $h), \InvalidArgumentException::class);
});
test('a provider error never carries the credential, and addresses are masked', function () use ($msg) {
    $h = new FakeHttp(401, '{"message":"bad key re_SECRETKEY_123 for ops@acme.com"}');
    $e = throws(fn () => (new ResendTransport('re_SECRETKEY_123', 'noreply@acme.com', '', $h))->send($msg()), MailException::class);
    ok(!str_contains($e->getMessage(), 're_SECRETKEY_123'), 'key leaked: ' . $e->getMessage());
    ok(!str_contains($e->getMessage(), 'ops@acme.com'), 'address leaked');
    ok(str_contains($e->getMessage(), 'HTTP 401'), 'status kept');
    $mg = new FakeHttp(0, '', 'Could not resolve host');
    $e2 = throws(fn () => (new MailgunTransport('key-abc', 'mg.acme.com', 'us', 'n@acme.com', '', $mg))->send($msg()), MailException::class);
    ok(str_contains($e2->getMessage(), 'request failed'), $e2->getMessage());
});

echo "\n\033[36m══ SMTP transport (scripted socket) ══\033[0m\n";

/** A fake SMTP server: replies are scripted per command; everything written is recorded. */
final class ScriptedSmtp
{
    public static array $replies = [];
    public static string $written = '';
    private string $buf = '';
    public $context;
    public function stream_open($p, $m, $o, &$op): bool { self::$written = ''; $this->buf = (string) array_shift(self::$replies); return true; }
    public function stream_read($n) { if ($this->buf === '') { return ''; } $line = substr($this->buf, 0, (int) strpos($this->buf, "\n") + 1); $this->buf = substr($this->buf, strlen($line)); return $line; }
    public function stream_write($d): int { self::$written .= $d; if (str_ends_with($d, "\r\n")) { $this->buf .= (string) array_shift(self::$replies); } return strlen($d); }
    public function stream_eof(): bool { return false; }
    public function stream_set_option($o, $a, $b): bool { return true; }
    public function stream_close(): void {}
}
stream_wrapper_register('smtpfake', ScriptedSmtp::class);
$connect = static fn (string $target, int $t) => fopen('smtpfake://x', 'r+');

test('plain SMTP with AUTH: full dialogue, dot-stuffed DATA, password never echoed in errors', function () use ($msg, $connect) {
    ScriptedSmtp::$replies = ["220 hi\r\n", "250-mx\r\n250 AUTH LOGIN\r\n", "334 VXNlcm5hbWU6\r\n", "334 UGFzc3dvcmQ6\r\n", "235 ok\r\n",
        "250 ok\r\n", "250 ok\r\n", "250 ok\r\n", "354 go\r\n", "250 queued\r\n", "221 bye\r\n"];
    (new SmtpTransport('127.0.0.1', 25, 'user', 'p4ss', 'none', 'noreply@acme.com', 'Acme', $connect))->send($msg());
    $w = ScriptedSmtp::$written;
    ok(str_contains($w, "MAIL FROM:<noreply@acme.com>\r\n"), 'mail from');
    ok(str_contains($w, "RCPT TO:<ops@acme.com>\r\nRCPT TO:<b@acme.com>\r\n"), 'both rcpt');
    ok(str_contains($w, "\r\n..\r\nline 3\r\n.\r\n"), 'dot-stuffed + terminator');
    ok(str_contains($w, base64_encode('p4ss')), 'auth sent');
});
test('SMTP 5xx → MailException without the password', function () use ($msg, $connect) {
    ScriptedSmtp::$replies = ["220 hi\r\n", "250 mx\r\n", "334 x\r\n", "334 y\r\n", "535 auth failed for " . base64_encode('p4ss') . "\r\n"];
    $e = throws(fn () => (new SmtpTransport('127.0.0.1', 25, 'user', 'p4ss', 'none', 'n@acme.com', '', $connect))->send($msg()), MailException::class);
    ok(str_contains($e->getMessage(), '535'), $e->getMessage());
    ok(!str_contains($e->getMessage(), base64_encode('p4ss')), 'password echoed');
});
test('tls mode refuses a server that does not offer STARTTLS', function () use ($msg, $connect) {
    ScriptedSmtp::$replies = ["220 hi\r\n", "250 mx\r\n"];
    $e = throws(fn () => (new SmtpTransport('mx.acme.com', 587, '', '', 'tls', 'n@acme.com', '', $connect))->send($msg()), MailException::class);
    ok(str_contains($e->getMessage(), 'STARTTLS'), $e->getMessage());
    ok(!str_contains(ScriptedSmtp::$written, 'MAIL FROM'), 'must not talk in clear text');
});

echo "\n\033[36m══ MailTransportFactory (env matrix) ══\033[0m\n";

test('off: unset, unknown, missing/invalid FROM', function () {
    $why = null;
    eq(null, MailTransportFactory::fromEnv(new FakeHttp(), [], $why));
    eq(null, MailTransportFactory::fromEnv(new FakeHttp(), ['FLUXFILES_MAIL_TRANSPORT' => 'sendmail', 'FLUXFILES_MAIL_FROM' => 'a@x.io'], $why));
    ok(str_contains((string) $why, 'not one of'), (string) $why);
    eq(null, MailTransportFactory::fromEnv(new FakeHttp(), ['FLUXFILES_MAIL_TRANSPORT' => 'resend', 'FLUXFILES_RESEND_API_KEY' => 'k'], $why));
    ok(str_contains((string) $why, 'FLUXFILES_MAIL_FROM'), (string) $why);
    eq(null, MailTransportFactory::fromEnv(new FakeHttp(), ['FLUXFILES_MAIL_TRANSPORT' => 'resend', 'FLUXFILES_MAIL_FROM' => "a@x.io\nBcc: v@e.io", 'FLUXFILES_RESEND_API_KEY' => 'k'], $why));
});
test('each transport builds when complete, and is off when its key is missing', function () {
    $base = ['FLUXFILES_MAIL_FROM' => 'n@acme.com'];
    $cases = [
        'smtp' => ['FLUXFILES_SMTP_HOST' => 'mx.acme.com'],
        'resend' => ['FLUXFILES_RESEND_API_KEY' => 'k'],
        'postmark' => ['FLUXFILES_POSTMARK_TOKEN' => 't'],
        'mailgun' => ['FLUXFILES_MAILGUN_API_KEY' => 'k', 'FLUXFILES_MAILGUN_DOMAIN' => 'mg.acme.com'],
        'ses' => ['FLUXFILES_SES_REGION' => 'us-east-1'],
    ];
    foreach ($cases as $kind => $extra) {
        $t = MailTransportFactory::fromEnv(new FakeHttp(), $base + $extra + ['FLUXFILES_MAIL_TRANSPORT' => $kind]);
        ok($t !== null && $t->name() === $kind, $kind . ' should build');
        eq(null, MailTransportFactory::fromEnv(new FakeHttp(), $base + ['FLUXFILES_MAIL_TRANSPORT' => $kind]), $kind . ' without its key');
    }
    $log = MailTransportFactory::fromEnv(null, ['FLUXFILES_MAIL_TRANSPORT' => 'LOG']);
    ok($log instanceof LogTransport, 'log');
});
test('smtp tls=none with a password is refused unless the host is loopback', function () {
    $env = ['FLUXFILES_MAIL_TRANSPORT' => 'smtp', 'FLUXFILES_MAIL_FROM' => 'n@acme.com', 'FLUXFILES_SMTP_TLS' => 'none', 'FLUXFILES_SMTP_USER' => 'u', 'FLUXFILES_SMTP_PASS' => 'p'];
    $why = null;
    eq(null, MailTransportFactory::fromEnv(null, $env + ['FLUXFILES_SMTP_HOST' => 'mx.acme.com'], $why));
    ok(str_contains((string) $why, 'loopback'), (string) $why);
    ok(MailTransportFactory::fromEnv(null, $env + ['FLUXFILES_SMTP_HOST' => '127.0.0.1']) !== null, 'loopback allowed');
    eq(null, MailTransportFactory::fromEnv(null, $env + ['FLUXFILES_SMTP_HOST' => '127.evil.com']), '127.<name> is not loopback');
    ok(MailTransportFactory::fromEnv(null, ['FLUXFILES_SMTP_USER' => ''] + $env + ['FLUXFILES_SMTP_HOST' => 'mx.acme.com']) !== null, 'no auth allowed');
    eq(null, MailTransportFactory::fromEnv(null, ['FLUXFILES_SMTP_TLS' => 'starttls'] + $env + ['FLUXFILES_SMTP_HOST' => 'mx.acme.com']), 'bad tls value');
});
test('the log transport masks addresses and redacts tokens', function () {
    $t = new LogTransport();
    $t->send(new MailMessage(['jane@acme.com'], 'Link', 'token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijklmnopqrstuv end'));
    ok(str_contains($t->sent[0], 'j•••@acme.com'), 'masked');
    ok(!str_contains($t->sent[0], 'eyJzdWIiOiIxMjM0NTY3ODkwIn0'), 'jwt redacted');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
