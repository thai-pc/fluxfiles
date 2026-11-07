# Intake + Share e-mail notifications (Intake document requests, step 1)

Status: **Design, not implemented.** Step 1 of "Intake document requests". Step 2
(required-document checklists, reminders, deadlines) is out of scope here. §12 shows where it attaches.

> **Scope.** E-mail notices for the paid **Intake** (upload portals) and **Share**
> (branded links) modules. Mail goes out through the **operator's own** SMTP server or
> provider key (Resend / Postmark / Mailgun / SES). FluxFiles hosts no mail and holds no
> mail account. No new SKU: this is Pro value inside `intake` / `share`.

## 1. Problem & who pays

Today an operator learns about a portal drop or a share download in one of three ways:
polling `GET /api/fm/{intake,share}/analytics`, opening the UI, or wiring Webhooks
(Studio, à-la-carte) to Zapier and then to e-mail. The buyers of the hero modules
(agencies and freelancers on WordPress, accountants and law firms collecting client
documents, photographers delivering work) expect a plain "you got files" or "your client
downloaded it" e-mail. Competitors treat this as table stakes. Dropbox file requests e-mail
the owner on every upload, free ([Dropbox help](https://help.dropbox.com/share/create-file-request)).
Document-collection SaaS charges for the next layer up, chasing clients with reminders:
Content Snare lists $29–$99/mo ([Capterra](https://www.capterra.com/p/167019/Content-Snare/pricing/)).
That next layer is step 2.

**Who pays: Intake/Share (Pro) licensees.** The events, digests, templates and sweeps
live in the gitignored `intake` / `share` packages behind the existing 3-layer gate
(installed + licensed + `allow_intake` / `allow_share`). Notifications need no Webhooks
licence. Core gets only an inert, generic mail transport and three thin routes (§5.1, §8).
On free core they answer `501 module_not_installed`.

## 2. Architecture fit

- **No new central state.** Recipients and policy are **claims**, baked into the
  per-portal/per-share record at create time. This is the same snapshot trade-off
  `brand`/`analytics`/`webhook` already make. Pending notices live in the tenant's storage
  under `_fluxfiles/`. Send caps use core's existing server-side rate limiter
  (`rate_limit.d/`, or the DB in `FLUXFILES_STORAGE_BACKEND=db` mode).
- **Event-driven, on the causing request.** Notices go out post-response
  (`fastcgi_finish_request()`), best-effort and at-most-once. These are the Webhooks
  semantics (`.claude/rules/webhooks.md`), wired at the **same hook points**:
  `PublicIntakeHandler`'s post-response block where `intake_received` already fires, and
  `PublicShareHandler`'s post-bytes block where `share_download` is audited. There is no
  queue and no retry loop.
- **Time-based events need no daemon.** Nothing happens at expiry time, so
  "expiring/expired" notices and the digest safety net run from
  `POST /api/fm/notify/run`. The operator's own scheduler calls it, exactly like
  `/retention/run` ("nothing runs on its own").
- **Mail credentials are server env, never claims (v1).** The From address must be on a
  domain verified with the provider, so it is an operator-wide property, not a per-tenant
  one. A JWT is signed, not encrypted, so a key in a claim would be readable by its
  holder. A sealed per-tenant key is possible later (§11 Q1) but not needed for v1.
- **Recipients are sealed in the record.** `intakes.json`/`shares.json` live in tenant
  storage, and a BYOB tenant holds the bucket keys. An unsealed `to` would let the tenant
  rewrite it to any address and use the operator's verified domain as a spam relay. This is
  the class of bug intake 1.5.1 closed for webhook blocks. The `notify` block is therefore
  one AES-256-GCM blob bound (AAD) to the record identity, using the same construction as
  `IntakeModule::sealWebhook()` with its own HKDF info strings.
- **Why not reuse Webhooks?** Its hook points and semantics are reused, but routing e-mail
  through `WebhooksModule` would make e-mail need a Webhooks licence and an HTTP receiver.
  Neither makes sense here.

### 2.1 Batching multi-file drops without a queue

The portal page fires **one `POST /intake/upload` per file** (in parallel,
`intake.html` `handle()`). One e-mail per file is unacceptable. A post-response
"sleep then check" waiter is also rejected: it holds an FPM worker for the debounce
window, blocks the sender's response on hosts without `fastcgi_finish_request`
(mod_php / WP shared hosting), and serialises `php -S`. The design uses a
**storage-resident digest plus several flush triggers**:

1. Each **successful** receipt appends `{name,size,ts,batch}` to
   `<store>/_fluxfiles/intake-notify/<jti>.json`. The append happens under the existing
   `intakes.lock`, in the same post-success section that appends the analytics event.
   Rejected, virus-blocked and DLP-blocked files never enter the digest.
2. **Explicit close (primary).** `intake.html` keeps an in-flight counter. When it drains
   to 0, the page calls `GET /api/fm/intake/info?token=…&flush=1`, using
   `fetch(…, {keepalive:true})` so the call also survives `pagehide`. The digest is flushed
   post-response. This reuses an existing public route, so the Laravel/WP proxies need no
   new public route.
3. **Lazy flush.** Any later upload/info request on that portal flushes the digest when it
   has been **quiet** for `notify_batch_window` (default 600 s), or when its oldest entry is
   older than **3 × window**. The second rule stops a steady trickle from deferring forever.
4. **Full.** The upload that makes `received === max_files` flushes immediately as
   `intake_full`.
5. **Sweep.** `POST /api/fm/notify/run` applies rule 3 to every portal. This covers
   API/curl senders that never call `flush=1` and tabs that were killed.

A flush first takes a **lease**: a rate-limiter bucket `notify_flush:<jti>` that allows 1
hit per 15 s. It then **claims** the digest under the lock: snapshot, clear, release. Only
after that does it send. The lease is what prevents double sends on S3/R2/SFTP, where
`withLock()` is a no-op. The claim-before-send order is what makes delivery at-most-once.
A stranger hammering `flush=1` after every single file gets at most
`FLUXFILES_NOTIFY_MAX_PER_HOUR` e-mails per portal. Past that cap the digest is **kept
pending, not dropped**, and goes out on the next trigger.

## 3. Events

| Event | Module | Recipient | Fires on | Content |
|---|---|---|---|---|
| `intake_received` | intake | portal recipients | digest flush (§2.1) | portal label, N files, total size, file list ≤ 50 names + sizes (+ "and K more"), time range, masked submitter e-mail if one was given, optional app deep link |
| `intake_full` | intake | portal recipients | the upload that fills `max_files` | as above + "portal is now full" |
| `intake_expiring` | intake | portal recipients | sweep, ≤ 48 h before expiry, once; only when the portal's lifetime is ≥ 72 h | label, received count, expiry time |
| `intake_receipt` | intake | **the submitter** (opt-in) | same flush, per `batch` | portal label (operator text), file **count** + total size, time. **No filenames, no links** (§6.3) |
| `share_first_download` | share | share recipients | the counted download where `downloads` goes 0→1, once | label, file name (if `notify_include_names`), time, remaining |
| `share_exhausted` | share | share recipients | the counted download where `downloads === max_downloads`, once | label, "download limit reached", counts |
| `share_expiring` | share | share recipients | sweep, ≤ 24 h before expiry, once; only when the share's lifetime is ≥ 72 h | label, views/downloads so far |
| `share_expired` | share | share recipients | sweep, after expiry, once, within 7 days of expiry | final views/downloads |

`first_download` and `exhausted` are decided **inside** `ShareModule::mutateRecord()`'s
locked increment. The mutator sets `notified.<event> = now` atomically with the counter
bump, so "first" is exactly-once on `local` and best-effort on S3. A Range continuation is
not counted, so it never fires an event. The on-S3 caveat matches the existing one:
`max_downloads` counts presign grants. The send happens after `sendBytes()` and after the
audit line, post-response.

Event names are reserved in one vocabulary shared with Webhooks, so a later Webhooks
release can emit `intake_full` / `share_first_download` without a rename.

## 4. JWT claims

All of these are read on the **operator's** token at `POST /intake` / `POST /share` and
baked into the record's sealed `notify` block. A public request reads none of them, and
none is forwarded into the portal or share JWT. They go in CONFIG.md §2.13 (paid-module
gates).

| Claim | Type | Default | Validation / notes |
|---|---|---|---|
| `notify_to` | string[] (or comma string) | `[]` | Recipients the operator vouches for. At most 5; each passes `FILTER_VALIDATE_EMAIL`, contains no CR/LF, is ≤ 254 chars and is lowercased and deduplicated. Invalid entries are dropped. **Empty = notifications off** (the opt-in layer, like an empty `webhook_url`). |
| `notify_recipient_domains` | string[] | `[]` | Domains (`^[a-z0-9.-]+$`, ≤ 10) whose addresses the create **body** may add as extra per-portal/per-share recipients. Empty = the body may only pick a subset of `notify_to`. |
| `notify_events` | string[] | `[]` (all) | Filter over the §3 names, lowercased; unknown names are dropped. Empty = all, the same convention as `webhook_events`. |
| `notify_locale` | string | — | One of the 16 shipped locales. Else the create body's `locale` (the operator UI sends its active locale). Else `en`. |
| `notify_from_name` | string | brand name → `FLUXFILES_MAIL_FROM_NAME` | Display name only, max 80 chars, control chars stripped. The **address** is always `FLUXFILES_MAIL_FROM`. |
| `notify_reply_to` | string (e-mail) | — | Validated like `notify_to`. Used as Reply-To on receipts, so a submitter can reply to the operator. |
| `notify_app_url` | string (http/s) | — | Optional "Open in your file manager" link in owner e-mails. `{path}` is replaced with the URL-encoded tenant-relative folder or file. Non-http(s) values are dropped. The URL is rendered only, never fetched. |
| `notify_batch_window` | int (s) | `600` | Quiet period for the intake digest, clamped to `[60, 3600]`. |
| `notify_include_names` | bool | `true` | `false` = owner e-mails carry counts and sizes only (filenames can be sensitive and pass through a third-party mail provider). |
| `intake_notify_receipt` | bool | `false` | Show an optional "e-mail me a receipt" field on the portal page and send `intake_receipt`. |

Builders need no new named options: the `claims` map (PHP/Node/Python/Laravel/WP)
already covers any raw claim name. `tests/unit/test-config-doc.php` enforces that every
`$payload->notify_*` read in `Claims.php` is documented.

## 5. Endpoints

### 5.1 Operator (main JWT; proxied by Laravel/WP)

- `POST /api/fm/intake` and `POST /api/fm/share`. The existing body gains
  `notify?: {enabled?: bool = true, to?: string[], locale?: string}`.
  `to` defaults to all of `notify_to`. Each entry must be in `notify_to` or on a
  `notify_recipient_domains` domain, otherwise **`403 notify_recipient_denied`**. A
  malformed entry returns **`400 notify_invalid_address`**. The response gains
  `notify: {enabled: bool, recipients: int}`.
- `GET /api/fm/{intake,share}/list`. Each record gains `has_notify`, `notify_recipients`
  (a count) and `notify_muted`. The sealed blob and the addresses are never returned,
  for the same reason `has_webhook` replaced the webhook block.
- `POST /api/fm/notify/test {to?}` sends a fixed test message to `to`, which must be in
  `notify_to`; the default is `notify_to[0]`. It returns
  `{sent: bool, transport: string, error: ?string}`, with the provider error scrubbed of
  keys. This mirrors `/webhooks/test`. Errors: `400 notify_not_configured` (no transport, or
  empty `notify_to`) and `403 notify_recipient_denied`. Gate: `intake` **or** `share`
  passes `ModuleRegistry::require()` (otherwise `501`/`402`/`403` from the first one
  tried). Rate limit: 5/min per user.
- `POST /api/fm/notify/run {disk, dry_run?: bool = false, max_emails?: int ≤ 200}` is the
  sweep. Core iterates the modules that pass `requireCapability(<id>,
  NotifyProviderInterface::class, …)` for this token. A module that predates the interface
  is reported as `skipped: module_update_required` and is not a 501. The other module still
  runs. Report:
  `{dry_run, sent, coalesced, rate_limited, truncated, items:[{module, jti, event, result}]}`.
  Needs `read`. Scope is one tenant prefix per call, as with `/retention/run`.
- `POST /api/fm/notify/mute {module: "intake"|"share", disk, jti, muted: bool}` is the
  "stop e-mailing me about this one" switch. It writes `notify.muted` **outside** the seal
  (muting can only reduce mail) and is owner-checked under `owner_only` like revoke.

Audit actions: `notify_test`, `notify_run`, `notify_mute`. These are added to
`AuditPolicy::ACTIONS`, which keeps the "every mutating route has a named action" CI guard
green.

### 5.2 Public (portal/share token; already gated by `PublicRoutes::dispatch()`)

- `POST /api/fm/intake/upload` gains optional fields:
  - `batch`: matches `^[A-Za-z0-9_-]{16,64}$` and is generated by the page per drop session.
  - `receipt_to`: honoured only when the portal has a receipt enabled. If it is invalid it
    is **silently ignored**, because a typo must never fail an upload.
  - `locale`: one of the 16 locales.

  The response shape is unchanged.
- `GET /api/fm/intake/info` gains `flush=1` (§2.1). The response gains
  `receipt: bool`, so the page knows whether to show the field.
- Share public routes: **no change**.
- **No new public routes**, so `PublicRoutes::ROUTES` and the Laravel/WP public
  registrations stay untouched.

## 6. Security

### 6.1 Credentials & transport
- Env-only (§9). There is no `sendmail`/`mail()` transport: it spawns a process and
  invites `-f`/header injection. Provider endpoints are **fixed hosts**
  (`api.resend.com`, `api.postmarkapp.com`, `api.mailgun.net` / `api.eu.mailgun.net`,
  the SDK's SES endpoint for a region validated `^[a-z]{2}-[a-z]+-\d$`). There is
  therefore no SSRF surface. The SMTP host is operator env and trusted, the same posture as
  `FLUXFILES_AIVISION_ENDPOINT`. `FLUXFILES_SMTP_TLS=none` combined with AUTH is refused
  unless the host is loopback.
- No tenant-controlled URL is ever fetched. The brand logo is not embedded (plain-text
  mail), and `notify_app_url` is rendered only.
- API keys never appear in logs, errors or `/notify/test` output. The `log` transport
  redacts long tokens, like `LicenseMailer::sendLog()`.

### 6.2 Message integrity
- `MailMessage` rejects CR/LF in every header value. It RFC 2047-encodes the subject and
  display name, control-strips and length-caps filenames (`cleanLabel(…, 200)`), and
  SMTP-dot-stuffs the body. Every message sets `Auto-Submitted: auto-generated` (RFC 3834)
  and `X-Auto-Response-Suppress: All`, so auto-responders do not loop.
- **v1 is plain text only.** Filenames are attacker-controlled. Plain text removes the
  HTML-injection class entirely, and it is the posture `LicenseMailer` already chose. A
  residual risk remains: a mail client may auto-link a URL-shaped filename in an *owner*
  e-mail. The owner knows these files come from strangers, and the risk is documented.

### 6.3 Abuse: the portal is public
| Vector | Control |
|---|---|
| Stranger floods a portal to spam the owner | Existing upload limits (10/min per portal+IP, 60/min per portal) **and** `FLUXFILES_NOTIFY_MAX_PER_HOUR` (6) per record. Excess is coalesced into the next digest, never dropped. |
| Many portals pointed at one inbox | `FLUXFILES_NOTIFY_ADDRESS_DAILY` (100) per recipient; the bucket key is `sha256(addr)`, so no address is stored in `rate_limit.d`. |
| Receipt to a victim's address (relay from the operator's domain) | Receipts are **off by default**. They are sent only for a batch with ≥ 1 successful file. They contain **no filenames and no links**; the only text is operator-authored (label, from name) plus a count, a size and a time. Caps: `FLUXFILES_NOTIFY_RECEIPT_LIMIT` (10/h per portal) and `FLUXFILES_NOTIFY_RECEIPT_ADDRESS_DAILY` (3 per address). A password-protected portal already requires the password on upload. |
| BYOB tenant rewrites `intakes.json` to retarget mail | The sealed, AAD-bound `notify` block fails to open, so nothing is sent (logged). It **never** falls back to claims. |
| Tenant resets `notified` flags or the digest | It can only re-trigger mail to **vetted** recipients, and the server-side caps still apply. |
| `flush=1` hammering | The lease (1 per 15 s) plus the hourly cap. A flush with nothing pending sends nothing. |

### 6.4 Privacy
- No file contents, attachments, thumbnails or file URLs that bypass auth. The only link
  is `notify_app_url`, which points at the operator's own login-gated app.
- No IP or user-agent in any e-mail. A submitter address appears in owner mail
  **masked** (`j•••@acme.com`, labelled unverified, since anyone can type any address).
  The plaintext address lives in the digest only until flush, then it is deleted.
- `notify_include_names=false` reduces owner mail to counts and sizes, for operators whose
  filenames are personal data.
- Each send writes one HMAC-chained audit line, `notify_sent`. Actor = owner; detail =
  `intake:<jti> event=… to=<n> files=<n>`. Addresses are never logged. This gives
  compliance tenants a record of filename egress to the mail provider.

### 6.5 Failure semantics
A notice never blocks or alters an upload or download. Everything runs after the response
inside `try/catch` and logs via `Log::warn`. Delivery is at-most-once: a provider outage
loses the **notice**, never the file, which is still in storage, the list and analytics.
Per-request send budget: connect 5 s, total 10 s per message, and at most ~20 s per
request. Hosts without `fastcgi_finish_request` make the sender wait for the send; this is
the same documented property Webhooks has.

### 6.6 New error codes (i18n × 16, in `lang/*.json` `error.*`)
`notify_not_configured` (400), `notify_recipient_denied` (403),
`notify_invalid_address` (400). Public routes add **no** error codes, because notice
problems are never surfaced to a sender.

## 7. Storage layout

```jsonc
// <store>/_fluxfiles/intakes.json — record gains (shares.json identical, plus `notified`)
"notify": {
  "sealed": "<b64 nonce|ct|tag>",   // AES-256-GCM, HKDF(FLUXFILES_SECRET, info "fluxfiles-intake-notify-v1"
                                    // | "fluxfiles-share-notify-v1"), AAD = webhookAad()-style JSON of
                                    // jti|disk|store|owner|path|label
  "muted": false                     // outside the seal on purpose: can only reduce mail
},
"notified": { "first_download": 0, "exhausted": 0, "expiring": 0, "expired": 0 }  // share; intake: {"expiring":0}

// sealed plaintext
{ "v":1, "to":["ops@acme.com"], "events":[], "locale":"de", "from_name":"Acme",
  "reply_to":"", "app_url":"", "window":600, "names":true, "receipt":false }

// <store>/_fluxfiles/intake-notify/<jti>.json — the pending digest (PRIVATE ACL)
{ "v":1, "first_at":1767000000, "last_at":1767000042,
  "files":[{"n":"contract.pdf","s":183422,"ts":1767000040,"b":"Zk3…"}],   // cap 500
  "overflow":{"count":0,"bytes":0},
  "receipts":{"Zk3…":{"to":"client@x.com","locale":"fr","count":3,"bytes":9123}} }  // cap 50
```

`IntakeModule::save()`'s pruning deletes `intake-notify/<jti>.json` together with the
analytics file. Legacy records with no `notify` key behave as off, so no migration is
needed. `FluxDirGuard` already covers `_fluxfiles/` at any depth.

## 8. Package layout

**Core (free/MIT, public repo): inert without the modules.**
- `api/Mail/{MailMessage, MailTransportInterface, MailTransportFactory, SmtpTransport,
  ResendTransport, PostmarkTransport, MailgunTransport, SesTransport, LogTransport}.php`.
  SMTP and Resend are adapted from `services/license-server/LicenseMailer.php`. Copy, don't
  couple: the license server stays dependency-free. HTTP providers go through the existing
  `HttpTransportInterface`/`CurlTransport` so tests can fake them.
  `composer.json`: add `SesV2` to the `aws/aws-sdk-php` kept-services list (`["S3","Sts"]`).
- `api/NotifyProviderInterface.php`:
  `runNotify(DiskManager, Claims, string $disk, MailTransportInterface, bool $dryRun, int $max): array`
  and `mute(DiskManager, Claims, string $disk, string $jti, bool $muted): array`.
- `api/PublicContext.php`: optional `?MailTransportInterface` constructor argument plus
  `mailer()`. Precedence: host-injected transport, else `MailTransportFactory::fromEnv()`,
  else null (off). `api/I18n.php`: a `forLocale()` helper so modules can render with core's
  lang dir.
- `api/index.php`: `/notify/test|run|mute`. `api/AuditPolicy.php`: 3 actions plus
  `notify_sent`. `api/Claims.php`: 10 claims.
- `lang/*.json` ×16: `notify.*` templates (subjects/bodies/footer), `error.notify_*`, and
  UI strings under `intake.*`/`share.*`.
- `assets/fm.js` + `public/index.html`: in the create dialogs, an "E-mail me" toggle and a
  recipient picker over `notify_to`, both inside the existing `proGate('allow_intake'|'allow_share')`.
  In the lists, a bell badge plus mute/unmute, and a "Send test e-mail" action.
- Laravel + WordPress: register the 3 operator routes and update the smoke allowlists. Add
  optional host transports (`WpMailTransport` → `wp_mail`, `LaravelMailTransport` →
  `Mail::raw`) injected into `PublicContext`, so WP SMTP plugins and Laravel `mail.php`
  work with zero `FLUXFILES_MAIL_*` env. This could ship as phase 1b (§11 Q8).
- Docs: `docs/reference/CONFIG.md`, `.claude/api-map.md`, a new `.claude/rules/notifications.md`,
  a one-line Current Note in `.claude/CLAUDE.md`, `.env.example`.

**Private `packages/intake/`.** In `IntakeModule`: `sealNotify`/`openNotify`, the
`notify` block in `createPortal()`, digest append in `receiveUpload()`'s success path,
`flushNotices()`, and `NotifyProviderInterface` (sweep + mute). In `PublicIntakeHandler`:
the new fields, plus post-response triggers after the existing webhook block and on
`info?flush=1`. Also `src/NotifyTemplates.php`. In `public/intake.html`: an in-flight
counter, the keepalive flush, a `batch` id, and the receipt field when `info.receipt` is
set. The field's label is English, like the rest of the page today (§11 Q9). Plus tests
and the CHANGELOG.

**Private `packages/share/`.** In `ShareModule`: seal/open, `notified` decided in
`mutateRecord`, `resolveShare()` returning `downloads`/`notify_events`, and the sweep +
mute. In `PublicShareHandler`: the post-bytes send. Plus templates, tests and the CHANGELOG.

**Floors.** Each module's `composer.json` floor is the first core release that ships
`Mail\*`, `NotifyProviderInterface` and `PublicContext::mailer()`. The `/notify/run` caller
lands in that same core release. Read the real next core version from
`git tag | grep '^core-v' | sort -V | tail -1`, never from the CHANGELOG. This must be
checked by hand, because the packages are gitignored.

## 9. Server env vars (CONFIG.md §4)

| Env var | Default | Notes |
|---|---|---|
| `FLUXFILES_MAIL_TRANSPORT` | `''` (off) | `smtp` \| `resend` \| `postmark` \| `mailgun` \| `ses` \| `log` (dev: writes to error_log, redacted). Unknown value = off (logged). |
| `FLUXFILES_MAIL_FROM` | — | Required when a transport is set. Must be on a domain verified with the provider. |
| `FLUXFILES_MAIL_FROM_NAME` | `FluxFiles` | Fallback display name. |
| `FLUXFILES_SMTP_HOST` / `_PORT` (`587`) / `_USER` / `_PASS` / `_TLS` (`tls`\|`ssl`\|`none`) | — | Same names as the license server's mailer. `none` + AUTH is refused unless the host is loopback. |
| `FLUXFILES_RESEND_API_KEY` | — | Resend. |
| `FLUXFILES_POSTMARK_TOKEN` / `FLUXFILES_POSTMARK_STREAM` (`outbound`) | — | Postmark server token / message stream. |
| `FLUXFILES_MAILGUN_API_KEY` / `_DOMAIN` / `_REGION` (`us`\|`eu`) | — | Mailgun. |
| `FLUXFILES_SES_REGION` | — | SES v2. Credentials come from the standard AWS chain (`AWS_*`, which EnvBootstrap already passes through). |
| `FLUXFILES_NOTIFY_MAX_PER_HOUR` | `6` | Owner e-mails per portal/share record per hour; excess is coalesced. |
| `FLUXFILES_NOTIFY_ADDRESS_DAILY` | `100` | E-mails per recipient address per day, across all records. |
| `FLUXFILES_NOTIFY_RECEIPT_LIMIT` | `10` | Submitter receipts per portal per hour. |
| `FLUXFILES_NOTIFY_RECEIPT_ADDRESS_DAILY` | `3` | Receipts per submitter address per day. |

## 10. Test plan

- **Core unit:**
  - `test-mail-message.php`: CRLF refused in every header, RFC 2047, dot-stuffing,
    `Auto-Submitted`.
  - `test-mail-transports.php`: fake `HttpTransportInterface` asserts the endpoint, auth
    header and JSON shape for Resend/Postmark/Mailgun/SES. Also: no key in thrown messages,
    and SMTP against a scripted in-memory stream (STARTTLS-less path, AUTH, 5xx).
  - `test-mail-factory.php`: env matrix (off / unknown / missing FROM / `tls=none` + AUTH
    to a non-loopback host).
  - `test-claims-notify.php`: all 10 claims (caps, lowercasing, drops, clamps).
  - `test-config-doc.php` stays green, and the AuditPolicy route→action guard covers the
    new routes.
  - Lang parity: the new keys are present in all 16 files.
- **Intake module (`tests/test-intake-notify.php`):**
  - The sealed block is present and the portal JWT carries no `notify_*`.
  - Body recipient outside the allowlist → 403; domain allowlist → OK.
  - A tampered or swapped sealed block → no send.
  - Only successes enter the digest (ext-rejected, virus and DLP fixtures excluded).
  - Flush rules: quiet window, 3× max latency, `full`, explicit. The hourly cap coalesces,
    and the lease blocks a double send.
  - Receipts: off by default; with no successes, nothing; never a filename or link in the
    body; an invalid `receipt_to` still uploads 200.
  - A throwing transport leaves the upload response unchanged and clears the digest.
  - Muted → nothing. A legacy record → nothing. `include_names=false` → no names.
- **Share module:** `first_download` fires once, including under concurrent downloads on
  `local`. `exhausted` fires at the cap. A Range continuation fires nothing. The
  `expiring`/`expired` sweep fires once each, and not for a lifetime < 72 h. The send
  happens after the bytes, so a failing transport does not break the download.
- **E2E:** a self-booting `tests/e2e/test-notify-http.php` on an **ephemeral** port (like
  `test-write-policy-http.php`). It starts an in-test SMTP sink (`stream_socket_server`,
  loopback) and sets `FLUXFILES_MAIL_TRANSPORT=smtp`. It drops 5 files and then
  `flush=1`, expecting exactly 1 message listing 5 names. Then: fill the cap → 1
  `intake_full`; share download → 1 `first_download`; `/notify/test`; `/notify/run`
  `dry_run`. It is skipped when the modules are absent, like the existing intake e2e.
- **Browser (module repo, `intake-landing.spec.ts`):** a multi-file drop → exactly one
  `flush=1` request after the last upload settles. The receipt field appears only when
  `info.receipt` is set.
- **Adapters:** the Laravel/WP smoke tests see the 3 new routes, and the host transport
  shims send through `wp_mail`/`Mail::raw` stubs.

## 11. Open questions & trade-offs

1. **Per-tenant BYO mail keys.** A SaaS operator may want each customer's own From domain.
   The proposed shape is a sealed `notify_mail_enc` claim, which builders seal like
   `webhook_secret_enc`. It would be restricted to HTTP-API providers (fixed hosts), and
   SMTP host/port would never come from claims, so no SSRF is added. **Recommend deferring
   to v1.1.** Env-only covers the "operator's own provider" brief.
2. **At-most-once vs one retry.** Claim-before-send loses a notice when the provider is
   down. A single re-queue (`attempts ≤ 1`, retried on the next trigger) is cheap and stays
   storage-resident, but it bends the Webhooks rule ("don't add retries"). The design
   **picks at-most-once**, for consistency and because the file itself is never lost.
3. **Submitter e-mail: masked or full** in the owner notice. Masking is the safer default
   because the address is unverified. Some owners will want the full address to follow up.
   A possible knob is `notify_show_submitter`.
4. **HTML + brand logo.** v1 is plain text only (§6.2). A branded HTML variant needs
   careful escaping and, if the logo is embedded, a fetch, and the logo URL is
   tenant-controlled, so that would mean SSRF. Defer.
5. **Expiry mail needs a scheduler.** Without anyone calling `/notify/run`,
   `*_expiring`/`*_expired` never fire. Digests still flush through the page and lazy
   triggers. An alternative: run the sweep post-response on the operator's own
   `/intake/list`/`/share/list`. That makes expiry mail work for active operators, at the
   cost of surprising side effects on a GET. Not adopted.
6. **Multi-tenant sweeps.** `/notify/run` is one prefix per token, like `/retention/run`.
   An operator with thousands of tenants must mint and call per tenant. An unscoped sweep
   would need an index of stores, which is new state. Accepted for v1.
7. **"Someone hit your expired link".** This is the only event-driven expiry signal. It
   needs a signature-verified decode of an **expired** share JWT (a new
   `JwtCompat` leeway helper), which is a security-sensitive core API. Deferred.
8. **Adapter host transports (`wp_mail` / Laravel Mail)** in v1 or 1b. They are high value
   for the WP persona and cost about 1.5 days plus adapter floor bumps.
9. **Recipient pages are English-only today** (`intake.html`, `share.html`). The receipt
   field inherits that gap. E-mails themselves are localised (16 languages).
10. **Webhooks parity.** Should `intake_full`/`share_*` also fire as webhook events? The
    names are reserved now; implementation is deferred.

## 12. Future step 2: checklists, reminders, deadlines (design room only)

- **Checklist.** The create body adds `request: {items:[{id,label,required,ext?}], due_at?}`,
  baked into the record (the label is operator text). The portal page lets the sender tag
  each upload to an item (`item` upload field). The digest file grows
  `items:{<id>:{count,last_at}}`. The data structure from §7 is reused unchanged.
- **Completion** is computed on the causing upload (all required items have ≥ 1 file) and
  fires `intake_complete` through the same flush path.
- **Reminders and deadlines** run through the **existing** `/notify/run` sweep
  (`NotifyProviderInterface::runNotify`). The schedule is `due_at - 3d`, `- 1d` and
  overdue, recorded in `notified.reminder_<n>` flags, exactly like `share_expiring`. Event
  names reserved: `intake_reminder`, `intake_overdue`, `intake_complete`.
- **Requestee mail** (send the portal link plus reminders to the client) is a vetted
  address supplied by the operator at create. It is sealed in the same `notify` block
  (`requestee`), so it is not stranger-supplied and is held to the
  `notify_recipient_domains` rule or a new `notify_requestee_any` claim. It uses the
  receipt template path with its own caps.

## 13. Effort estimate

| Piece | Days |
|---|---|
| Core mail transports + factory + `MailMessage` + tests | 2.5 |
| Core routes, `NotifyProviderInterface`, `PublicContext::mailer()`, AuditPolicy, claims, CONFIG.md | 2 |
| i18n: templates + 3 error codes × 16 locales | 1 |
| Intake module (seal, digest, flush triggers, receipts, sweep, page) + tests | 3 |
| Share module (events in `mutateRecord`, sweep) + tests | 1.5 |
| Operator UI (create toggles, list badge, mute, test) | 1.5 |
| Laravel/WP routes + host transports + smoke tests | 1.5–2 |
| E2E + browser spec + docs | 1.5 |
| **Total** | **≈ 14–15 dev-days (~3 weeks)**, ~11 without the adapter host transports |

Release: one core minor (new routes, interface and claims), then `intake` + `share` minor
releases with raised floors (§8), adapter tags if 1b is in, and a CHANGELOG `> Released:`
line naming the exact tags.

## Summary: claims to add to `docs/reference/CONFIG.md`

§2.13 (paid-module gates):
`notify_to`, `notify_recipient_domains`, `notify_events`, `notify_locale`,
`notify_from_name`, `notify_reply_to`, `notify_app_url`, `notify_batch_window`,
`notify_include_names`, `intake_notify_receipt`.

§4 (server env):
`FLUXFILES_MAIL_TRANSPORT`, `FLUXFILES_MAIL_FROM`, `FLUXFILES_MAIL_FROM_NAME`,
`FLUXFILES_SMTP_HOST`, `FLUXFILES_SMTP_PORT`, `FLUXFILES_SMTP_USER`, `FLUXFILES_SMTP_PASS`,
`FLUXFILES_SMTP_TLS`, `FLUXFILES_RESEND_API_KEY`, `FLUXFILES_POSTMARK_TOKEN`,
`FLUXFILES_POSTMARK_STREAM`, `FLUXFILES_MAILGUN_API_KEY`, `FLUXFILES_MAILGUN_DOMAIN`,
`FLUXFILES_MAILGUN_REGION`, `FLUXFILES_SES_REGION`, `FLUXFILES_NOTIFY_MAX_PER_HOUR`,
`FLUXFILES_NOTIFY_ADDRESS_DAILY`, `FLUXFILES_NOTIFY_RECEIPT_LIMIT`,
`FLUXFILES_NOTIFY_RECEIPT_ADDRESS_DAILY`.
