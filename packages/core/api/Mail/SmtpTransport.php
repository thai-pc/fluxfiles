<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a raw SMTP socket (stream_socket_client/fwrite/fclose); WP_Filesystem does not apply; core is a WordPress-independent library

/**
 * Minimal SMTP client: optional implicit TLS (`ssl`) or STARTTLS (`tls`), AUTH LOGIN, one
 * DATA per message. Adapted from the licence server's mailer (copied, not shared: the
 * licence server stays dependency-free).
 *
 * Env: `FLUXFILES_SMTP_HOST`, `_PORT` (587), `_USER`, `_PASS`, `_TLS` (`tls`|`ssl`|`none`).
 * With `tls`, a server that does not offer STARTTLS is refused rather than spoken to in
 * clear text. `none` + AUTH to a non-loopback host is refused by {@see MailTransportFactory}.
 */
final class SmtpTransport implements MailTransportInterface
{
    /** Whole-message budget (connect + dialogue), seconds. */
    public const TIMEOUT = 10;

    /** Connect budget, seconds. */
    public const CONNECT_TIMEOUT = 5;

    /** @var (\Closure(string $target, int $timeout): (resource|false))|null */
    private ?\Closure $connect;

    /**
     * @param (\Closure(string $target, int $timeout): (resource|false))|null $connect test seam; null = stream_socket_client
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $pass,
        private readonly string $tls,
        private readonly string $fromAddress,
        private readonly string $fromName,
        ?\Closure $connect = null
    ) {
        if (!in_array($tls, ['tls', 'ssl', 'none'], true)) {
            throw new \InvalidArgumentException('FLUXFILES_SMTP_TLS must be tls, ssl or none');
        }
        if (preg_match('/^[A-Za-z0-9.:\[\]-]{1,253}$/', $host) !== 1 || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('invalid SMTP host or port');
        }
        $this->connect = $connect;
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(MailMessage $m): void
    {
        $deadline = microtime(true) + self::TIMEOUT;
        $target = ($this->tls === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $fp = $this->connect !== null
            ? ($this->connect)($target, self::CONNECT_TIMEOUT)
            : @stream_socket_client($target, $errno, $errstr, self::CONNECT_TIMEOUT);
        if (!is_resource($fp)) {
            throw new MailException('smtp: could not connect to ' . $this->host . ':' . $this->port);
        }
        $secrets = [$this->pass, base64_encode($this->pass), base64_encode($this->user)];
        $user64 = base64_encode($this->user);
        $pass64 = base64_encode($this->pass);
        $helo = substr($this->fromAddress, (int) strrpos($this->fromAddress, '@') + 1);

        $read = static function () use ($fp, $deadline): string {
            $out = '';
            while (true) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0 || strlen($out) > 65536) {
                    throw new MailException('smtp: timeout');
                }
                @stream_set_timeout($fp, max(0, (int) floor($remaining)), max(1, (int) (($remaining - floor($remaining)) * 1000000)));
                $line = fgets($fp, 515);
                if ($line === false) {
                    break;
                }
                $out .= $line;
                // A multi-line reply keeps a '-' in the 4th column until its last line.
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $out;
        };
        // #[\SensitiveParameter]: the AUTH lines pass through here, keep them out of traces (PHP 8.2+).
        $cmd = static function (#[\SensitiveParameter] string $line, string $expect) use ($fp, $read, $secrets): string {
            if ($line !== '') {
                fwrite($fp, $line . "\r\n");
            }
            $reply = $read();
            if (strncmp($reply, $expect, strlen($expect)) !== 0) {
                throw MailException::scrubbed('smtp: expected ' . $expect . ', got ' . trim($reply), $secrets);
            }
            return $reply;
        };

        try {
            $cmd('', '220');
            $ehlo = $cmd('EHLO ' . $helo, '250');
            if ($this->tls === 'tls') {
                if (stripos($ehlo, 'STARTTLS') === false) {
                    throw new MailException('smtp: server does not offer STARTTLS (set FLUXFILES_SMTP_TLS=ssl or none)');
                }
                $cmd('STARTTLS', '220');
                // Plaintext pipelined after the 220 would be read as a post-TLS reply.
                if ((int) (stream_get_meta_data($fp)['unread_bytes'] ?? 0) > 0) {
                    throw new MailException('smtp: unexpected data before the TLS handshake');
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new MailException('smtp: STARTTLS handshake failed');
                }
                $cmd('EHLO ' . $helo, '250');   // capabilities are re-read after the upgrade
            }
            if ($this->user !== '') {
                $cmd('AUTH LOGIN', '334');
                $cmd($user64, '334');
                $cmd($pass64, '235');
            }
            $cmd('MAIL FROM:<' . $this->fromAddress . '>', '250');
            foreach ($m->to as $to) {
                $cmd('RCPT TO:<' . $to . '>', '250');
            }
            $cmd('DATA', '354');
            $cmd($m->toMime($this->fromAddress, $this->fromName) . "\r\n.", '250');
            try {
                $cmd('QUIT', '221');
            } catch (MailException $ignored) {
                // The message was accepted at the final '.', a sloppy QUIT changes nothing.
            }
        } finally {
            fclose($fp);
        }
    }
}
