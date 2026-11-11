<?php

declare(strict_types=1);

namespace FluxFiles;

use FluxFiles\Mail\MailTransportInterface;

/**
 * Implemented by the `intake` and `share` modules to own their e-mail notices: the
 * time-based sweep behind `POST /api/fm/notify/run` (expiring / expired notices and the
 * intake digest safety net) and the per-record mute behind `POST /api/fm/notify/mute`.
 * Event-driven notices (a portal drop, a first download) are sent by the module's own
 * public handlers, post-response; core only supplies the transport
 * ({@see PublicContext::mailer()}).
 *
 * A module release that does not implement this is reported by `/notify/run` as
 * `skipped: module_update_required`; `/notify/mute` answers 501 for it.
 */
interface NotifyProviderInterface
{
    /**
     * One sweep over this tenant's records on `$disk`. `$mailer` is always null on a dry
     * run: the module must then report, never send. `$maxEmails` ≥ 1 is this module's
     * remaining share of the call's budget (core never calls with 0).
     *
     * Report shape (core merges the two modules' reports):
     * `{sent:int, coalesced:int, rate_limited:int, truncated:bool,
     *   items: list<{jti:string, event:string, result:string}>}`
     * `result` is `sent|would_send|coalesced|rate_limited|failed`.
     *
     * @return array<string,mixed>
     */
    public function runNotify(DiskManager $dm, Claims $claims, string $disk, ?MailTransportInterface $mailer, bool $dryRun, int $maxEmails): array;

    /**
     * Mute or unmute one record's notices (owner-checked under `owner_only`, like revoke).
     * Muting is stored outside the record's sealed `notify` block: it can only reduce mail.
     *
     * @return array{jti:string, muted:bool}
     */
    public function mute(DiskManager $dm, Claims $claims, string $disk, string $jti, bool $muted): array;
}
