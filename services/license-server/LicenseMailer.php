<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

require_once __DIR__ . '/ProDownload.php';
if (is_file(__DIR__ . '/client/Mask.php')) { require_once __DIR__ . '/client/Mask.php'; }

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
    private ?\Closure $onResult;
    private string $kind = 'licence';
    private ?string $providerId = null;

    private const SITE = 'https://fluxfiles.thai-pc.com';

    public function __construct(
        private string $transport = '',
        private string $from = '',
        private string $fromName = '',
        ?callable $onResult = null
    ) {
        $this->onResult = $onResult === null ? null : \Closure::fromCallable($onResult);
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
        $this->kind = trim((string) ($record['modules'] ?? '')) === '' ? 'support' : 'licence';
        try {
            return $this->sendLicenseImpl($record);
        } catch (\Throwable $e) {
            error_log('license mail failed for jti ' . (string) ($record['jti'] ?? '?') . ': ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $record */
    private function sendLicenseImpl(array $record): bool
    {
        $to = (string) ($record['email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('license mail: no valid recipient for jti ' . (string) ($record['jti'] ?? '?'));
            return false;
        }

        $isSupportOnly = trim((string) ($record['modules'] ?? '')) === '';
        $subject = $isSupportOnly ? 'Your FluxFiles Priority Support subscription' : 'Your FluxFiles licence key';
        $content = $isSupportOnly ? $this->supportContent($record) : $this->licenseContent($record);
        return $this->deliverContent($to, $subject, $content, 'license mail failed for ');
    }

    /**
     * A renewal was paid but no lifetime licence matches it. Tells the buyer (and the
     * optional FLUXFILES_OPS_EMAIL) so a paid order is never silent. Never throws.
     */
    public function sendRenewalUnmatched(string $email, string $plan, string $orderId): bool
    {
        $this->kind = 'renewal_unmatched';
        try {
            return $this->sendRenewalUnmatchedImpl($email, $plan, $orderId);
        } catch (\Throwable $e) {
            error_log('unmatched-renewal mail failed: ' . $e->getMessage());
            return false;
        }
    }

    private function sendRenewalUnmatchedImpl(string $email, string $plan, string $orderId): bool
    {
        $ok = true;
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $ok = $this->deliverContent($email, 'We could not match your FluxFiles renewal', [
                'heading' => 'We could not match your renewal',
                'preheader' => 'Nothing is lost: reply with your original purchase email.',
                'intro' => [
                    "Thanks for your FluxFiles updates renewal ({$plan}).",
                    'We could not match it to a FluxFiles Lifetime licence under this email address '
                    . '(or this customer account), so nothing has been extended yet and nothing is lost.',
                    'Please reply to this email with the address you used for your original Lifetime '
                    . "purchase and we will apply the renewal, or refund it if you prefer. Order: {$orderId}.",
                ],
            ], 'license mail failed for ');
        }
        $ops = (string) (getenv('FLUXFILES_OPS_EMAIL') ?: '');
        if ($ops !== '' && filter_var($ops, FILTER_VALIDATE_EMAIL)) {
            $this->deliver($ops, "Unmatched renewal: {$plan} ({$orderId})",
                "Order {$orderId} ({$plan}) by {$email} matched no active Lifetime licence.\n"
                . "Fix: POST /issue {\"plan\":\"{$plan}\",\"email\":\"<the LIFETIME purchase email>\"}, or refund in Polar.");
        }
        return $ok;
    }

    /** Operator notice to FLUXFILES_OPS_EMAIL (no-op when unset). Plain text. Never throws. */
    public function sendOpsNotice(string $subject, string $body): bool
    {
        $this->kind = 'ops';
        $ops = (string) (getenv('FLUXFILES_OPS_EMAIL') ?: '');
        if ($ops === '' || !filter_var($ops, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        return $this->deliver($ops, $subject, $body);
    }

    /**
     * Customer mail: the content model rendered as a text part plus an HTML part.
     *
     * @param array<string,mixed> $content see prepare()
     */
    private function deliverContent(string $to, string $subject, array $content, string $failPrefix): bool
    {
        $content['to'] = $to;
        $content['subject'] = $subject;
        $c = $this->prepare($content);
        $text = $this->renderText($c);
        // A broken HTML template (missing file, bad deploy) must not stop a paid order's
        // key from being mailed: send the text part alone and say so loudly.
        $html = null;
        try {
            $html = $this->renderHtml($c);
            // An empty or truncated template must not replace the key with a blank mail body.
            if (trim($html) === '') {
                throw new \RuntimeException('template rendered empty');
            }
            $key = (string) ($c['key'] ?? '');
            if ($key !== '' && !str_contains($html, htmlspecialchars($key, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                throw new \RuntimeException('template output lacks the licence key');
            }
        } catch (\Throwable $e) {
            $html = null;
            error_log('LICENSE MAIL: HTML template failed (' . $e->getMessage() . ') — sending text-only; copy the whole services/license-server dir incl. templates/');
        }
        return $this->deliver($to, $subject, $text, $html, $failPrefix);
    }

    private function deliver(string $to, string $subject, string $body, ?string $html = null, string $failPrefix = 'license mail failed for '): bool
    {
        $ok = false; $error = null; $this->providerId = null;
        try {
            if (!$this->recipientAllowed($to)) {
                error_log('mail suppressed by FLUXFILES_MAIL_ALLOW_TO (to=' . self::maskEmail($to) . ')');
                return $ok = true;
            }
            $subject = self::subjectPrefix() . $subject;
            return $ok = match ($this->transport) {
                'resend' => $this->sendResend($to, $subject, $body, $html),
                'smtp' => $this->sendSmtp($to, $subject, $body, $html),
                'sendmail' => $this->sendMail($to, $subject, $body, $html),
                default => $this->sendLog($to, $subject, $body),
            };
        } catch (\Throwable $e) {
            $error = 'mail transport failed';
            error_log($failPrefix . self::maskEmail($to) . ': ' . $error);
            return false;
        } finally {
            if ($this->onResult !== null) {
                try { ($this->onResult)($this->kind, $to, $ok, $error, $this->providerId); }
                catch (\Throwable $e) { error_log('mail result recording failed'); }
            }
        }
    }

    public function sendTest(): bool
    {
        $this->kind = 'test';
        $to = (string) (getenv('FLUXFILES_OPS_EMAIL') ?: '');
        return filter_var($to, FILTER_VALIDATE_EMAIL) !== false && $this->deliver($to, 'FluxFiles admin test', 'Licence server mail test.');
    }

    /**
     * FLUXFILES_MAIL_SUBJECT_PREFIX with a trailing space, or '' when unset. Control
     * characters (CR/LF included, so it can never inject a header) are stripped and the
     * value is capped at 32 characters.
     */
    private static function subjectPrefix(): string
    {
        $p = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', (string) getenv('FLUXFILES_MAIL_SUBJECT_PREFIX'));
        $p = trim(substr($p, 0, 32));
        return $p === '' ? '' : $p . ' ';
    }

    /**
     * FLUXFILES_MAIL_ALLOW_TO: comma-separated exact addresses and/or "@domain" suffixes,
     * case-insensitive. Unset or empty = no restriction.
     */
    private function recipientAllowed(string $to): bool
    {
        $raw = trim((string) getenv('FLUXFILES_MAIL_ALLOW_TO'));
        if ($raw === '') {
            return true;
        }
        $to = strtolower(trim($to));
        foreach (explode(',', $raw) as $rule) {
            $rule = strtolower(trim($rule));
            if ($rule === '') {
                continue;
            }
            if ($rule[0] === '@' ? str_ends_with($to, $rule) : $to === $rule) {
                return true;
            }
        }
        return false;
    }

    private static function maskEmail(string $e): string
    {
        $at = strrpos($e, '@');
        return $at === false ? '***' : substr($e, 0, 1) . '***' . substr($e, $at);
    }

    // ── Content model ────────────────────────────────────────────────────────
    //
    // Every customer mail is described once as an array and rendered twice (text part
    // and templates/email.php), so the two parts cannot drift apart:
    //   heading, preheader, intro[] (paragraphs), plan?, key?, steps[]? ({text, code?}),
    //   notes[] (paragraphs), wp? ({url, text}), button? ([label, url]), closing[].
    // `to` and `subject` are added by deliverContent().

    /**
     * Text + HTML for one content model (the HTML throws if the template is unusable; the
     * senders go through deliverContent(), which degrades to text-only instead).
     *
     * @param array<string,mixed> $c
     * @return array{text:string,html:string}
     */
    private function build(array $c): array
    {
        $c = $this->prepare($c);
        return ['text' => $this->renderText($c), 'html' => $this->renderHtml($c)];
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function prepare(array $c): array
    {
        $c['site'] = self::SITE;
        $origin = rtrim((string) getenv('FLUXFILES_LANDING_ORIGIN'), '/');
        $base = ($origin !== '' && str_starts_with($origin, 'https://')) ? $origin : self::SITE;
        $c['button'] = ['Read the docs', $base . '/docs'];
        $c += ['intro' => [], 'notes' => [], 'closing' => [], 'preheader' => (string) ($c['heading'] ?? ''), 'to' => '', 'subject' => ''];
        return $c;
    }

    /** @param array<string,mixed> $c */
    private function renderText(array $c): string
    {
        $out = [(string) $c['heading'], ''];
        foreach ($c['intro'] as $para) { $out[] = (string) $para; $out[] = ''; }
        if (isset($c['key'])) {
            $out[] = 'Plan: ' . (string) $c['plan'];
            $out[] = '';
            $out[] = 'Your licence key:';
            $out[] = '';
            $out[] = (string) $c['key'];
            $out[] = '';
        }
        if (!empty($c['steps'])) {
            $out[] = 'Next steps:';
            $out[] = '';
            foreach ($c['steps'] as $i => $s) {
                $out[] = ($i + 1) . '. ' . (string) $s['text'];
                if (isset($s['code'])) {
                    foreach (explode("\n", (string) $s['code']) as $line) { $out[] = '       ' . $line; }
                }
                $out[] = '';
            }
        }
        foreach ($c['notes'] as $para) { $out[] = (string) $para; $out[] = ''; }
        if (isset($c['wp'])) {
            $out[] = 'WordPress: get the FluxFiles Pro plugin here (paste the key above):';
            $out[] = '';
            $out[] = '    ' . (string) $c['wp']['url'];
            $out[] = '';
            $out[] = (string) $c['wp']['text'];
            $out[] = '';
        }
        $out[] = $c['button'][0] . ': ' . $c['button'][1];
        $out[] = '';
        foreach ($c['closing'] as $para) { $out[] = (string) $para; $out[] = ''; }
        $out[] = 'Questions? Write to fluxfiles@thai-pc.com or just reply to this email. Replies reach a real person.';
        $out[] = 'Sent to ' . (string) $c['to'] . '.';
        $out[] = '';
        $out[] = '— FluxFiles by thai-pc · ' . self::SITE;
        return implode("\n", $out);
    }

    /** @param array<string,mixed> $c */
    private function renderHtml(array $c): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // href is the one attribute a hostile value could turn into script: https only.
        $href = static fn (string $u): ?string => (str_starts_with($u, 'https://') && preg_match('/[\s"\'<>]/', $u) !== 1) ? $u : null;
        $tpl = __DIR__ . '/templates/email.php';
        if (!is_file($tpl)) {
            // A failed `require` is a fatal compile error that no catch can see.
            throw new \RuntimeException('templates/email.php is missing');
        }
        ob_start();
        try {
            require $tpl;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** "Pro", "Studio Lifetime", "Pro updates renewal", "Studio Monthly" — for the plan line. */
    private function planLabel(array $record): string
    {
        $edition = ucfirst((string) ($record['edition'] ?? 'pro'));
        $plan = (string) ($record['plan'] ?? '');
        if (str_ends_with($plan, '-renewal')) { return $edition . ' updates renewal'; }
        if ($plan === 'lifetime' || $plan === 'pro-lifetime') { return $edition . ' Lifetime'; }
        if (str_ends_with($plan, '-monthly')) { return $edition . ' Monthly'; }
        return $edition;
    }

    /** @return string[] module ids of the record, e.g. ['share','intake'] */
    private function modulesOf(array $record): array
    {
        $mods = array_filter(array_map('trim', explode(',', (string) ($record['modules'] ?? ''))), static fn (string $m): bool => $m !== '');
        return array_values($mods);
    }

    /** The token claim (or env switch) that turns a module on. */
    private function claimFor(string $module): string
    {
        return match ($module) {
            'ai' => 'allow_ai_vision',
            'virus' => 'allow_virus_scan',
            'dlp' => 'allow_dlp_scan',
            'sso' => 'FLUXFILES_SSO_ENABLED=true (env, not a claim)',
            default => 'allow_' . str_replace('-', '_', $module),
        };
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function licenseContent(array $record): array
    {
        $key = (string) ($record['license_key'] ?? '');
        $edition = ucfirst((string) ($record['edition'] ?? 'pro'));
        $expires = $record['expires'] ?? null;
        $plan = (string) ($record['plan'] ?? '');
        $isRenewal = str_ends_with($plan, '-renewal');
        $isLifetime = $plan === 'lifetime' || $plan === 'pro-lifetime';
        $date = $expires ? gmdate('Y-m-d', (int) $expires) : '';
        if (!$expires) {
            $notes = ['This licence includes lifetime use and lifetime updates.'];
        } elseif ($isRenewal) {
            $notes = ["Your updates and priority support now run until: {$date}.",
                'Your previous key keeps running your modules, but its updates stop on its original date.'];
        } elseif ($isLifetime) {
            $notes = ["This is a one-time purchase: you can use your paid modules forever.",
                "Updates and priority support are included for 12 months, until: {$date}. After that your modules keep running; "
                . 'a one-time updates renewal (12 more months) restores new releases and support.'];
        } else {
            $notes = ["Updates and priority support are included until: {$date}.",
                'Your installed paid modules keep running after that date; renewal only restores new releases and support.'];
        }

        // Activation order matters: the key must be in place BEFORE `fluxfiles update`
        // (which authenticates with it), and a claim does nothing until the module exists.
        $mods = $this->modulesOf($record);
        $steps = [];
        $steps[] = $isRenewal
            ? ['text' => 'Replace the FLUXFILES_LICENSE_KEY on your server with the key above: only this key carries the extended update window.',
               'code' => 'FLUXFILES_LICENSE_KEY=<the key above>']
            : ['text' => 'Set the key in your .env (or export it in the environment your PHP process reads). It is verified offline: FluxFiles never phones home.',
               'code' => 'FLUXFILES_LICENSE_KEY=<the key above>'];
        $steps[] = [
            'text' => $isRenewal ? 'Pull the new releases of your modules:' : 'Install your paid modules (one command per module):',
            'code' => implode("\n", array_map(static fn (string $m): string => 'php vendor/bin/fluxfiles update ' . $m, $mods)),
        ];
        if (!$isRenewal) {
            $steps[] = [
                'text' => 'Enable the modules in the token claims your app mints (for example allow_share):',
                'code' => implode("\n", array_map(fn (string $m): string => $this->claimFor($m), $mods)),
            ];
        }

        // WordPress Pro plugin zip: no bearer URL in the mail. The landing /download page takes
        // the key and hands out a fresh short-lived link (nothing to expire in the inbox).
        $wp = null;
        $origin = rtrim((string) getenv('FLUXFILES_LANDING_ORIGIN'), '/');
        // Only when the page can actually work: it needs the update-server URL on this server too.
        if ($origin !== '' && str_starts_with($origin, 'https://') && ProDownload::configured() && ProDownload::eligible($record)) {
            $wp = ['url' => "{$origin}/download",
                'text' => 'Upload it in Plugins > Add New > Upload Plugin (the free FluxFiles plugin must be active), then paste the key in Settings > FluxFiles. Later updates arrive in wp-admin.'];
        }
        $thanks = $isRenewal
            ? "Thanks for renewing FluxFiles {$edition} updates."
            : "Thanks for buying FluxFiles {$edition}" . ($isLifetime ? ' Lifetime' : '') . '.';

        $c = [
            'heading' => $isRenewal ? 'Your renewed licence key' : 'Your FluxFiles licence key',
            'preheader' => $thanks . ' Your licence key and activation steps are inside.',
            'intro' => [$thanks],
            'plan' => $this->planLabel($record),
            'key' => $key,
            'steps' => $steps,
            'notes' => $notes,
            'closing' => ['Keep this email: it is the copy of the key you own. If you lose it, reply and we can look it up.'],
        ];
        if ($wp !== null) { $c['wp'] = $wp; }
        return $c;
    }

    /**
     * Text part of the licence mail (kept as a method so tests and previews can render it).
     *
     * @param array<string,mixed> $record
     */
    private function renderBody(array $record): string
    {
        return $this->contentText($this->licenseContent($record));
    }

    /** @param array<string,mixed> $content */
    private function contentText(array $content): string
    {
        return $this->renderText($this->prepare($content));
    }

    /**
     * Content for a Support-only subscription (modules=[], nothing to activate).
     * No `FLUXFILES_LICENSE_KEY=` line: a support purchase unlocks no software, so
     * that instruction would be actively wrong for this record.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function supportContent(array $record): array
    {
        $expires = $record['expires'] ?? null;
        $expiryLine = $expires
            ? 'Renews/expires: ' . gmdate('Y-m-d', (int) $expires)
            : 'No expiry on file.';

        return [
            'heading' => 'Welcome to Priority Support',
            'preheader' => 'Reply to this email any time you need help.',
            'intro' => [
                'Thanks for subscribing to FluxFiles Priority Support.',
                "This purchase does not unlock any module or feature. It's a support subscription for faster, prioritized responses on the free MIT core (or any paid modules you already run separately).",
                "Just reply to this email any time you need help, and it'll be flagged as priority.",
            ],
            'notes' => [$expiryLine],
        ];
    }

    /** @param array<string,mixed> $record */
    private function renderSupportBody(array $record): string
    {
        return $this->contentText($this->supportContent($record));
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
        $this->kind = 'reminder:' . $bucket;
        try {
            return $this->sendReminderImpl($record, $bucket);
        } catch (\Throwable $e) {
            error_log('license reminder mail failed for jti ' . (string) ($record['jti'] ?? '?') . ': ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $record */
    private function sendReminderImpl(array $record, string $bucket): bool
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
            $content = $isSupportOnly
                ? $this->expirySupportContent($record, $bucket)
                : $this->expiryContent($record, $bucket);
        } else {
            $subject = $isSupportOnly
                ? 'Your FluxFiles Priority Support subscription renews soon'
                : 'Your FluxFiles licence renews soon';
            $content = $isSupportOnly
                ? $this->renewalSupportContent($record, $bucket)
                : $this->renewalContent($record, $bucket);
        }

        // Same recovery story as sendLicense(): on failure the row stays at its previous
        // reminder_stage, so the next scheduled run retries it.
        return $this->deliverContent($to, $subject, $content, 'license reminder mail failed for ');
    }

    /**
     * "Renewal approaching" content for a module licence.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function renewalContent(array $record, string $bucket): array
    {
        $edition = ucfirst((string) ($record['edition'] ?? 'pro'));
        $expires = (int) ($record['expires'] ?? 0);
        $date = gmdate('Y-m-d', $expires);
        // The actual remaining days, not the threshold bucket that triggered this
        // email (e.g. a cron outage can cross several thresholds at once, so
        // "bucket=30" could mean 22 days actually left) — this must agree with $date.
        $daysLeft = max(0, (int) floor(($expires - time()) / 86400));

        $plan = (string) ($record['plan'] ?? '');
        if ($plan === 'lifetime' || $plan === 'pro-lifetime' || str_ends_with($plan, '-renewal')) {
            // One-time plans do not renew by themselves: only the update window ends.
            $url = (string) (getenv('FLUXFILES_RENEWAL_URL') ?: '');
            $where = $url !== '' ? " at {$url}" : ' (reply to this email for the link)';
            return [
                'heading' => 'Your updates window ends soon',
                'preheader' => "Updates and priority support end on {$date}.",
                'intro' => [
                    "Your FluxFiles {$edition} updates and priority support end on {$date} ({$daysLeft} day(s) left).",
                    'Your modules keep running either way. To get 12 more months of updates, buy the '
                    . "one-time updates renewal{$where}, using the SAME email address (and account) as your "
                    . 'original purchase, then replace FLUXFILES_LICENSE_KEY with the new key we email you.',
                ],
            ];
        }

        return [
            'heading' => 'Your licence renews soon',
            'preheader' => "Your FluxFiles {$edition} licence renews/expires on {$date}.",
            'intro' => [
                "Your FluxFiles {$edition} licence renews/expires on {$date} ({$daysLeft} day(s) left).",
                'If you already renewed, you can ignore this — the update to your record can lag '
                . 'a day or two behind payment. Otherwise, renew before the date above to keep your '
                . 'update channel open (see the Notes below for what happens if it lapses).',
            ],
        ];
    }

    /** @param array<string,mixed> $record */
    private function renderRenewalBody(array $record, string $bucket): string
    {
        return $this->contentText($this->renewalContent($record, $bucket));
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function renewalSupportContent(array $record, string $bucket): array
    {
        $expires = (int) ($record['expires'] ?? 0);
        $date = gmdate('Y-m-d', $expires);
        $daysLeft = max(0, (int) floor(($expires - time()) / 86400));

        return [
            'heading' => 'Your Priority Support renews soon',
            'preheader' => "Your Priority Support subscription renews on {$date}.",
            'intro' => [
                "Your FluxFiles Priority Support subscription renews on {$date} ({$daysLeft} day(s) left).",
                "This purchase doesn't unlock any module — it's your priority-response support subscription. Renew before the date above to keep priority handling on your tickets.",
            ],
        ];
    }

    /**
     * "Past expiry" content for a module licence, worded per `enforcement` — this is
     * the one place the copy must differ, not just the subject. `perpetual`
     * (annual/lifetime-use self-host) keeps running past expiry, only the update
     * channel stops; `subscription` (hosted/monthly) genuinely stops working past
     * the grace window. Getting these two swapped would tell a still-working
     * customer their software is broken, or a broken customer that it's fine.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function expiryContent(array $record, string $bucket): array
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

        return [
            'heading' => 'Your licence has expired',
            'preheader' => "Your FluxFiles {$edition} licence passed its expiry date ({$date}).",
            'intro' => ["Your FluxFiles {$edition} licence has passed its expiry date.", $consequence],
        ];
    }

    /** @param array<string,mixed> $record */
    private function renderExpiryBody(array $record, string $bucket): string
    {
        return $this->contentText($this->expiryContent($record, $bucket));
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function expirySupportContent(array $record, string $bucket): array
    {
        $expires = (int) ($record['expires'] ?? 0);
        $date = gmdate('Y-m-d', $expires);
        $consequence = $bucket === 'grace'
            ? "You're in a short grace period before priority handling on your tickets ends."
            : 'Priority handling on your tickets has now ended — you can still reach us through normal support channels.';

        return [
            'heading' => 'Your Priority Support has lapsed',
            'preheader' => "Your Priority Support subscription lapsed on {$date}.",
            'intro' => [
                "Your FluxFiles Priority Support subscription lapsed on {$date}.",
                "{$consequence} Renew any time to restore priority handling.",
            ],
        ];
    }

    /** @param array<string,mixed> $record */
    private function renderExpirySupportBody(array $record, string $bucket): string
    {
        return $this->contentText($this->expirySupportContent($record, $bucket));
    }

    /**
     * Message headers + body for the wire. With an HTML part this is multipart/alternative
     * (text first, HTML last: the last part is the preferred one) with a random boundary
     * and quoted-printable parts, so no line exceeds the 998-octet SMTP limit. Without
     * one it is the original single text/plain message.
     *
     * @return array{headers:string[],body:string}
     */
    private function mime(string $text, ?string $html): array
    {
        $from = 'From: ' . $this->encodeName($this->fromName) . ' <' . $this->from . '>';
        if ($html === null) {
            return ['headers' => [$from, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'], 'body' => $text];
        }
        $b = 'ff_' . bin2hex(random_bytes(16));
        $part = static fn (string $type, string $content): string =>
            "--{$b}\r\nContent-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode((string) preg_replace('/\r?\n/', "\r\n", $content)) . "\r\n";
        return [
            'headers' => [$from, 'MIME-Version: 1.0', "Content-Type: multipart/alternative; boundary=\"{$b}\""],
            'body' => $part('text/plain', $text) . $part('text/html', $html) . "--{$b}--\r\n",
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
        // Server logs are read by more people and kept longer than a mailbox: show
        // the message, but not the working key in it — unless the operator asks
        // (FLUXFILES_MAIL_LOG_FULL=1, e.g. while testing a setup).
        if (PHP_SAPI !== 'cli' || $this->onResult !== null || getenv('FLUXFILES_MAIL_LOG_FULL') !== '1') {
            $body = (string) preg_replace_callback(
                '/[A-Za-z0-9_-]{16,}(?:\.[A-Za-z0-9_+\/=-]{16,})+/',
                static fn (array $m): string => substr($m[0], 0, 12) . '…[redacted]',
                $body
            );
        }
        if (PHP_SAPI !== 'cli' || $this->onResult !== null) {
            $to = self::maskEmail($to);
            if (class_exists(\FluxFiles\LicenseServer\Client\Mask::class, false)) {
                $subject = \FluxFiles\LicenseServer\Client\Mask::clean($subject);
                $body = \FluxFiles\LicenseServer\Client\Mask::clean($body);
            }
        }
        error_log("license mail [log transport] to={$to} subject={$subject}\n{$body}");
        return true;
    }

    private function sendMail(string $to, string $subject, string $body, ?string $html = null): bool
    {
        $m = $this->mime($body, $html);
        return @mail($to, $subject, $m['body'], implode("\r\n", $m['headers']));
    }

    /** @return array<string,mixed> the JSON body of Resend's POST /emails: both `text` and `html` */
    private function resendPayload(string $to, string $subject, string $body, ?string $html, string $replyTo): array
    {
        $payload = [
            'from' => $this->fromName !== '' ? "{$this->fromName} <{$this->from}>" : $this->from,
            'to' => [$to],
            'subject' => $subject,
            'text' => $body,
        ];
        if ($html !== null) {
            $payload['html'] = $html;
        }
        if ($replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }
        return $payload;
    }

    /**
     * Resend's HTTP API — one POST, no SMTP conversation to hand-roll.
     *
     * Env: FLUXFILES_RESEND_API_KEY (required), FLUXFILES_MAIL_REPLY_TO (optional).
     * `from` reuses the constructor's $from/$fromName — Resend requires that address
     * to be on a domain verified in the Resend account, or the send is rejected.
     */
    private function sendResend(string $to, string $subject, string $body, ?string $html = null): bool
    {
        $apiKey = (string) (getenv('FLUXFILES_RESEND_API_KEY') ?: '');
        if ($apiKey === '') {
            throw new \RuntimeException('FLUXFILES_RESEND_API_KEY is not set');
        }
        $replyTo = (string) (getenv('FLUXFILES_MAIL_REPLY_TO') ?: '');

        $payload = $this->resendPayload($to, $subject, $body, $html, $replyTo);

        $base = (string) (getenv('FLUXFILES_RESEND_API_BASE') ?: 'https://api.resend.com');
        if ($base !== 'https://api.resend.com' && !preg_match('~^http://127\.0\.0\.1(?::[0-9]{1,5})?(?:/[A-Za-z0-9_/-]*)?$~D', $base)) { throw new \RuntimeException('invalid Resend API base'); }
        $ch = curl_init($base . '/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_FOLLOWLOCATION => false,
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

        if ($errno !== 0) {
            throw new \RuntimeException('Resend request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Resend API returned ' . $status . ': ' . (string) $responseBody);
        }
        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $this->providerId = is_string($decoded['id'] ?? null) ? mb_substr($decoded['id'], 0, 100) : null;
        return true;
    }

    /**
     * Minimal SMTP with STARTTLS + AUTH LOGIN — enough for any transactional provider.
     *
     * Env: FLUXFILES_SMTP_HOST / _PORT / _USER / _PASS / _TLS (tls|ssl|none)
     */
    private function sendSmtp(string $to, string $subject, string $body, ?string $html = null): bool
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

            $mime = $this->mime($body, $html);
            $headers = implode("\r\n", array_merge($mime['headers'], [
                'To: <' . $to . '>',
                'Subject: ' . $subject,
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            ]));
            // Dot-stuffing: a line that is just "." would end DATA early.
            $safeBody = preg_replace('/^\./m', '..', preg_replace('/\r?\n/', "\r\n", $mime['body']));
            $cmd($headers . "\r\n\r\n" . $safeBody . "\r\n.", '250');
            $cmd('QUIT', '221');
            return true;
        } finally {
            if (is_resource($fp)) { fclose($fp); }
        }
    }
}
