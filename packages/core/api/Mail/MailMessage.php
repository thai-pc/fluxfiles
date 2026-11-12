<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- mail-layer exceptions are reported in the JSON error envelope or the server log, never rendered as HTML

/**
 * One plain-text e-mail, validated at construction so no transport has to.
 *
 * Every header value refuses CR/LF (header injection), recipients are validated and
 * lower-cased, and the subject / display name are length-capped. The body is plain text
 * only: filenames in it come from strangers (portal uploads), and plain text removes the
 * HTML-injection class entirely (the same choice the licence server's mailer made).
 *
 * The From ADDRESS is not part of the message: it is an operator-wide property of the
 * transport (`FLUXFILES_MAIL_FROM`, a domain verified with the provider). A message only
 * carries an optional display name.
 */
final class MailMessage
{
    public const MAX_RECIPIENTS = 10;

    /** Sent with every message (RFC 3834 + Exchange): auto-responders must not answer. */
    public const AUTO_HEADERS = [
        'Auto-Submitted' => 'auto-generated',
        'X-Auto-Response-Suppress' => 'All',
    ];

    /** @var list<string> */
    public readonly array $to;
    public readonly string $subject;
    public readonly string $text;
    public readonly string $fromName;
    public readonly string $replyTo;

    /**
     * @param list<string>|string $to
     * @throws \InvalidArgumentException on any invalid recipient or a CR/LF in a header value
     */
    public function __construct(array|string $to, string $subject, string $text, string $fromName = '', string $replyTo = '')
    {
        $list = [];
        foreach ((array) $to as $addr) {
            $ok = self::validAddress(is_string($addr) ? $addr : '');
            if ($ok === null) {
                throw new \InvalidArgumentException('invalid recipient address');
            }
            $list[$ok] = true;
        }
        if ($list === [] || count($list) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('a message needs 1-' . self::MAX_RECIPIENTS . ' recipients');
        }
        if ($replyTo !== '' && self::validAddress($replyTo) === null) {
            throw new \InvalidArgumentException('invalid reply-to address');
        }
        foreach ([$subject, $fromName] as $h) {
            if (self::hasLineBreak($h)) {
                throw new \InvalidArgumentException('line break in a header value');
            }
        }
        if (preg_match('//u', $subject . $text . $fromName) !== 1) {
            throw new \InvalidArgumentException('message is not valid UTF-8');
        }
        $this->to = array_keys($list);
        $this->subject = mb_substr(self::stripControl($subject), 0, 200);
        $this->text = str_replace(["\r\n", "\r"], "\n", $text);
        $this->fromName = mb_substr(self::stripControl($fromName), 0, 80);
        $this->replyTo = $replyTo === '' ? '' : (string) self::validAddress($replyTo);
    }

    /**
     * A normalised address, or null. Lower-cased, ≤ 254 chars, no CR/LF, passes
     * FILTER_VALIDATE_EMAIL. Used for recipients, reply-to and every claim that holds one.
     */
    public static function validAddress(string $addr): ?string
    {
        $addr = strtolower(trim($addr));
        if ($addr === '' || strlen($addr) > 254 || self::hasLineBreak($addr) || preg_match('/[\x00-\x20\x7F<>]/', $addr)) {
            return null;
        }
        if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        // FILTER_VALIDATE_EMAIL accepts address literals (`a@[10.0.0.5]`, `a@[IPv6:::1]`):
        // through an operator's relay that would point mail at an internal host. Require a
        // dotted DNS hostname as the domain.
        $domain = substr($addr, (int) strrpos($addr, '@') + 1);
        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $domain) === 1 ? $addr : null;
    }

    /** `j•••@acme.com` — how an unverified, stranger-typed address appears in owner mail. */
    public static function maskAddress(string $addr): string
    {
        $at = strrpos($addr, '@');
        if ($at === false || $at === 0) {
            return '•••';
        }
        return mb_substr($addr, 0, 1) . '•••' . substr($addr, $at);
    }

    /**
     * Control characters stripped, whitespace collapsed, length-capped. For any
     * attacker-controlled text (filenames) that ends up inside a message body.
     */
    public static function cleanLabel(string $s, int $max = 200): string
    {
        if (preg_match('//u', $s) !== 1) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = (string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /**
     * RFC 2047 B-encoding for a non-ASCII header value; ASCII passes through. Encoded
     * words are folded with CRLF+space for an SMTP header; `$fold = false` joins them with
     * a plain space for a provider API field, where a CRLF would be rejected.
     */
    public static function encodeHeader(string $value, bool $fold = true): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        // Encode per ≤ 45-byte UTF-8 chunk so no encoded-word exceeds 75 chars (RFC 2047 §2).
        $words = [];
        $chunk = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($chunk . $ch) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $ch;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode($fold ? "\r\n " : ' ', $words);
    }

    /**
     * `"Name" <addr>` (or the bare address when there is no name), header-safe. `$fold`
     * as in {@see encodeHeader()}: true for an SMTP header, false for an HTTP API field.
     */
    public static function mailbox(string $address, string $name = '', bool $fold = true): string
    {
        $name = self::stripControl($name);
        if ($name === '') {
            return $address;
        }
        $display = preg_match('/[^\x20-\x7E]/', $name) === 1
            ? self::encodeHeader($name, $fold)
            : '"' . str_replace(['\\', '"'], '', $name) . '"';
        return $display . ' <' . $address . '>';
    }

    /**
     * A complete RFC 5322 message for SMTP: headers + CRLF body, dot-stuffed so a line
     * holding a single "." cannot end DATA early. The body goes 8bit unless a line would
     * pass RFC 5321's 998-octet limit (a long `notify_app_url`): then quoted-printable,
     * which also needs no 8BITMIME from the server.
     */
    public function toMime(string $fromAddress, string $fromName): string
    {
        $domain = substr($fromAddress, (int) strrpos($fromAddress, '@') + 1);
        $crlf = str_replace("\n", "\r\n", $this->text);
        $longLine = false;
        foreach (explode("\r\n", $crlf) as $line) {
            if (strlen($line) > 998) {
                $longLine = true;
                break;
            }
        }
        $headers = [
            'From: ' . self::mailbox($fromAddress, $this->fromName !== '' ? $this->fromName : $fromName),
            'To: ' . implode(', ', $this->to),
            'Subject: ' . self::encodeHeader($this->subject),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: ' . ($longLine ? 'quoted-printable' : '8bit'),
        ];
        if ($this->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }
        foreach (self::AUTO_HEADERS as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        $body = $longLine ? quoted_printable_encode($crlf) : $crlf;
        $body = (string) preg_replace('/^\./m', '..', $body);
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function hasLineBreak(string $s): bool
    {
        return strpbrk($s, "\r\n") !== false;
    }

    private static function stripControl(string $s): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
    }
}
