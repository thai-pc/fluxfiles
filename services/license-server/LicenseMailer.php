<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Delivers a freshly issued licence key to the buyer.
 *
 * This is the step that was missing: the webhook minted a key and returned it in a
 * response body that every gateway discards, so the key reached nobody and each order
 * had to be fulfilled by hand.
 *
 * Deliberately dependency-free — this service is a few files of plain PHP and a SQLite
 * file, and pulling a mail library (plus its transitive tree) into the one process that
 * holds the Ed25519 signing key is a bad trade for what is a short SMTP conversation.
 *
 * Transport is chosen by env:
 *   FLUXFILES_MAIL_TRANSPORT = resend | smtp | sendmail | log   (default: log)
 *
 * `log` writes the message to error_log instead of sending. That is the default on
 * purpose: an unconfigured server must not look like it delivered mail, and during
 * setup the operator can read exactly what would have gone out.
 */
final class LicenseMailer
{
    public function __construct(
        private string $transport = '',
        private string $from = '',
        private string $fromName = ''
    ) {
        $this->transport = $transport !== '' ? $transport : (string) (getenv('FLUXFILES_MAIL_TRANSPORT') ?: 'log');
        $this->from = $from !== '' ? $from : (string) (getenv('FLUXFILES_MAIL_FROM') ?: 'licenses@localhost');
        $this->fromName = $fromName !== '' ? $fromName : (string) (getenv('FLUXFILES_MAIL_FROM_NAME') ?: 'FluxFiles');
    }

    /**
     * Send the licence key for one issued record.
     *
     * Never throws: the purchase already succeeded and the record is already stored, so
     * a mail outage must not turn into a non-2xx that makes the gateway retry and the
     * buyer wonder whether they were charged. A failure is logged and the key remains
     * recoverable through the admin endpoint.
     *
     * @param array<string,mixed> $record a row as returned by LicenseStore::record()
     */
    public function sendLicense(array $record): bool
    {
        $to = (string) ($record['email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('license mail: no valid recipient for jti ' . (string) ($record['jti'] ?? '?'));
            return false;
        }

        $isSupportOnly = trim((string) ($record['modules'] ?? '')) === '';
        $subject = $isSupportOnly ? 'Your FluxFiles Priority Support subscription' : 'Your FluxFiles licence key';
        $body = $isSupportOnly ? $this->renderSupportBody($record) : $this->renderBody($record);

        try {
            return match ($this->transport) {
                'resend' => $this->sendResend($to, $subject, $body),
                'smtp' => $this->sendSmtp($to, $subject, $body),
                'sendmail' => $this->sendMail($to, $subject, $body),
                default => $this->sendLog($to, $subject, $body),
            };
        } catch (\Throwable $e) {
            // The key is not lost — it is in the store, retrievable by the operator.
            error_log('license mail failed for ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $record */
    private function renderBody(array $record): string
    {
        $key = (string) ($record['license_key'] ?? '');
        $edition = ucfirst((string) ($record['edition'] ?? 'pro'));
        $expires = $record['expires'] ?? null;
        $expiryLine = $expires
            ? 'Valid until: ' . gmdate('Y-m-d', (int) $expires)
            : 'This licence does not expire.';

        // Plain text on purpose: a licence key is a long opaque string, and HTML mail
        // clients are the reason keys arrive with smart quotes or a soft line break in
        // the middle. Plain text is also what survives forwarding into a ticket.
        return <<<TXT
        Thanks for buying FluxFiles {$edition}.

        Your licence key:

        {$key}

        To activate, set it on your server:

            FLUXFILES_LICENSE_KEY={$key}

        …in your .env, or export it in the environment your PHP process reads. The key is
        verified offline — FluxFiles never phones home — so nothing else is required.

        {$expiryLine}

        Keep this email: it is the copy of the key you own. If you lose it, reply and we
        can look it up.

        — FluxFiles
        TXT;
    }

    /**
     * Body for a Support-only subscription (modules=[], nothing to activate).
     * No `FLUXFILES_LICENSE_KEY=` line — a support purchase unlocks no software, so
     * that instruction would be actively wrong for this record.
     *
     * @param array<string,mixed> $record
     */
    private function renderSupportBody(array $record): string
    {
        $expires = $record['expires'] ?? null;
        $expiryLine = $expires
            ? 'Renews/expires: ' . gmdate('Y-m-d', (int) $expires)
            : 'No expiry on file.';

        return <<<TXT
        Thanks for subscribing to FluxFiles Priority Support.

        This purchase does not unlock any module or feature — it's a support
        subscription for faster, prioritized responses on the free MIT core (or any
        paid modules you already run separately).

        Just reply to this email any time you need help, and it'll be flagged as
        priority.

        {$expiryLine}

        — FluxFiles
        TXT;
    }

    /**
     * Send a renewal/expiry reminder for a licence already on file — the proactive
     * counterpart to sendLicense() above, invoked by
     * `send-renewal-reminders.php` for a row LicenseStore::needingReminder()
     * returned.
     *
     * Same never-throws posture as sendLicense(): a mail outage here must not crash
     * the cron run for every other row still due. `$bucket` is one of the
     * configured day thresholds (e.g. "7"), or "grace"/"expired" — see
     * LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md §6.
     *
     * @param array<string,mixed> $record a row as returned by LicenseStore::record()/needingReminder()
     */
    public function sendReminder(array $record, string $bucket): bool
    {
        $to = (string) ($record['email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('license reminder mail: no valid recipient for jti ' . (string) ($record['jti'] ?? '?'));
            return false;
        }

        $isSupportOnly = trim((string) ($record['modules'] ?? '')) === '';
        $isExpiryTouchpoint = $bucket === 'grace' || $bucket === 'expired';

        if ($isExpiryTouchpoint) {
            $subject = $isSupportOnly
                ? 'Your FluxFiles Priority Support subscription has expired'
                : 'Your FluxFiles licence has expired';
            $body = $isSupportOnly
                ? $this->renderExpirySupportBody($record, $bucket)
                : $this->renderExpiryBody($record, $bucket);
        } else {
            $subject = $isSupportOnly
                ? 'Your FluxFiles Priority Support subscription renews soon'
                : 'Your FluxFiles licence renews soon';
            $body = $isSupportOnly
                ? $this->renderRenewalSupportBody($record, $bucket)
                : $this->renderRenewalBody($record, $bucket);
        }

        try {
            return match ($this->transport) {
                'resend' => $this->sendResend($to, $subject, $body),
                'smtp' => $this->sendSmtp($to, $subject, $body),
                'sendmail' => $this->sendMail($to, $subject, $body),
                default => $this->sendLog($to, $subject, $body),
            };
        } catch (\Throwable $e) {
            // Same recovery story as sendLicense(): the row stays at its previous
            // reminder_stage, so the next scheduled run retries it.
            error_log('license reminder mail failed for ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * "Renewal approaching" body for a module licence. Plain text for the same
     * reason renderBody() is: this is exactly the kind of message that gets
     * forwarded into a support ticket.
     *
     * @param array<string,mixed> $record
     */
    private function renderRenewalBody(array $record, string $bucket): string
    {
        $edition = ucfirst((string) ($record['edition'] ?? 'pro'));
        $expires = (int) ($record['expires'] ?? 0);
        $date = gmdate('Y-m-d', $expires);
        // The actual remaining days, not the threshold bucket that triggered this
        // email (e.g. a cron outage can cross several thresholds at once, so
        // "bucket=30" could mean 22 days actually left) — this must agree with $date.
        $daysLeft = max(0, (int) floor(($expires - time()) / 86400));

        return <<<TXT
        Your FluxFiles {$edition} licence renews/expires on {$date} ({$daysLeft} day(s) left).

        If you already renewed, you can ignore this — the update to your record can lag
        a day or two behind payment. Otherwise, renew before the date above to keep your
        update channel open (see the Notes below for what happens if it lapses).

        — FluxFiles
        TXT;
    }

    /** @param array<string,mixed> $record */
    private function renderRenewalSupportBody(array $record, string $bucket): string
    {
        $expires = (int) ($record['expires'] ?? 0);
        $date = gmdate('Y-m-d', $expires);
        $daysLeft = max(0, (int) floor(($expires - time()) / 86400));

        return <<<TXT
        Your FluxFiles Priority Support subscription renews on {$date} ({$daysLeft} day(s) left).

        This purchase doesn't unlock any module — it's your priority-response support
        subscription. Renew before the date above to keep priority handling on your
        tickets.

        — FluxFiles
        TXT;
    }

    /**
     * "Past expiry" body for a module licence, worded per `enforcement` — this is
     * the one place the copy must differ, not just the subject. `perpetual`
     * (annual/lifetime self-host) keeps running past expiry, only the update
     * channel stops; `subscription` (hosted/monthly) genuinely stops working past
     * the grace window. Getting these two swapped would tell a still-working
     * customer their software is broken, or a broken customer that it's fine.
     *
     * @param array<string,mixed> $record
     */
    private function renderExpiryBody(array $record, string $bucket): string
    {
        $edition = ucfirst((string) ($record['edition'] ?? 'pro'));
        $expires = (int) ($record['expires'] ?? 0);
        $date = gmdate('Y-m-d', $expires);
        $enforcement = (string) ($record['enforcement'] ?? 'perpetual');

        if ($enforcement === 'subscription') {
            $consequence = $bucket === 'grace'
                ? "Your subscription lapsed on {$date}. You're currently in a short grace period — after it ends, your paid features (Share/Intake/…) will stop working for your users until you renew."
                : "Your subscription lapsed on {$date} and the grace period has now ended — your paid features (Share/Intake/…) have stopped working for your users. Renew to restore them immediately; no reinstall needed.";
        } else {
            $consequence = "Your update access ended on {$date}; the software keeps working exactly as before — you just won't receive new releases or security patches until you renew.";
        }

        return <<<TXT
        Your FluxFiles {$edition} licence has passed its expiry date.

        {$consequence}

        — FluxFiles
        TXT;
    }

    /** @param array<string,mixed> $record */
    private function renderExpirySupportBody(array $record, string $bucket): string
    {
        $expires = (int) ($record['expires'] ?? 0);
        $date = gmdate('Y-m-d', $expires);
        $consequence = $bucket === 'grace'
            ? "You're in a short grace period before priority handling on your tickets ends."
            : 'Priority handling on your tickets has now ended — you can still reach us through normal support channels.';

        return <<<TXT
        Your FluxFiles Priority Support subscription lapsed on {$date}.

        {$consequence} Renew any time to restore priority handling.

        — FluxFiles
        TXT;
    }

    private function headers(): array
    {
        return [
            'From: ' . $this->encodeName($this->fromName) . ' <' . $this->from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
    }

    /** RFC 2047 for a non-ASCII display name. */
    private function encodeName(string $name): string
    {
        return preg_match('/[^\x20-\x7E]/', $name) === 1
            ? '=?UTF-8?B?' . base64_encode($name) . '?='
            : '"' . str_replace('"', '', $name) . '"';
    }

    private function sendLog(string $to, string $subject, string $body): bool
    {
        error_log("license mail [log transport] to={$to} subject={$subject}\n{$body}");
        return true;
    }

    private function sendMail(string $to, string $subject, string $body): bool
    {
        return @mail($to, $subject, $body, implode("\r\n", $this->headers()));
    }

    /**
     * Resend's HTTP API — one POST, no SMTP conversation to hand-roll.
     *
     * Env: FLUXFILES_RESEND_API_KEY (required), FLUXFILES_MAIL_REPLY_TO (optional).
     * `from` reuses the constructor's $from/$fromName — Resend requires that address
     * to be on a domain verified in the Resend account, or the send is rejected.
     */
    private function sendResend(string $to, string $subject, string $body): bool
    {
        $apiKey = (string) (getenv('FLUXFILES_RESEND_API_KEY') ?: '');
        if ($apiKey === '') {
            throw new \RuntimeException('FLUXFILES_RESEND_API_KEY is not set');
        }
        $replyTo = (string) (getenv('FLUXFILES_MAIL_REPLY_TO') ?: '');

        $payload = [
            'from' => $this->fromName !== '' ? "{$this->fromName} <{$this->from}>" : $this->from,
            'to' => [$to],
            'subject' => $subject,
            'text' => $body,
        ];
        if ($replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('Resend request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Resend API returned ' . $status . ': ' . (string) $responseBody);
        }
        return true;
    }

    /**
     * Minimal SMTP with STARTTLS + AUTH LOGIN — enough for any transactional provider.
     *
     * Env: FLUXFILES_SMTP_HOST / _PORT / _USER / _PASS / _TLS (tls|ssl|none)
     */
    private function sendSmtp(string $to, string $subject, string $body): bool
    {
        $host = (string) (getenv('FLUXFILES_SMTP_HOST') ?: '');
        $port = (int) (getenv('FLUXFILES_SMTP_PORT') ?: 587);
        $user = (string) (getenv('FLUXFILES_SMTP_USER') ?: '');
        $pass = (string) (getenv('FLUXFILES_SMTP_PASS') ?: '');
        $tls = strtolower((string) (getenv('FLUXFILES_SMTP_TLS') ?: 'tls'));
        if ($host === '') {
            throw new \RuntimeException('FLUXFILES_SMTP_HOST is not set');
        }

        $target = ($tls === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($target, $errno, $errstr, 15);
        if ($fp === false) {
            throw new \RuntimeException("connect {$target}: {$errstr}");
        }
        stream_set_timeout($fp, 15);

        $read = static function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                // A multi-line reply keeps a '-' in the 4th column until the last line.
                if (strlen($line) < 4 || $line[3] !== '-') { break; }
            }
            return $out;
        };
        $cmd = static function (string $c, string $expect) use ($fp, $read): void {
            if ($c !== '') { fwrite($fp, $c . "\r\n"); }
            $reply = $read();
            if (strncmp($reply, $expect, strlen($expect)) !== 0) {
                throw new \RuntimeException('SMTP: expected ' . $expect . ', got ' . trim($reply));
            }
        };

        try {
            $cmd('', '220');
            $cmd('EHLO fluxfiles', '250');
            if ($tls === 'tls') {
                $cmd('STARTTLS', '220');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $cmd('EHLO fluxfiles', '250');   // capabilities are re-read after the upgrade
            }
            if ($user !== '') {
                $cmd('AUTH LOGIN', '334');
                $cmd(base64_encode($user), '334');
                $cmd(base64_encode($pass), '235');
            }
            $cmd('MAIL FROM:<' . $this->from . '>', '250');
            $cmd('RCPT TO:<' . $to . '>', '250');
            $cmd('DATA', '354');

            $headers = implode("\r\n", array_merge($this->headers(), [
                'To: <' . $to . '>',
                'Subject: ' . $subject,
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            ]));
            // Dot-stuffing: a line that is just "." would end DATA early.
            $safeBody = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body));
            $cmd($headers . "\r\n\r\n" . $safeBody . "\r\n.", '250');
            $cmd('QUIT', '221');
            return true;
        } finally {
            if (is_resource($fp)) { fclose($fp); }
        }
    }
}
