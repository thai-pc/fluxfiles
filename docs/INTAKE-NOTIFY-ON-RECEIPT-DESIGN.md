# Intake notify-on-receipt (→ Webhooks)

> **Scope note.** This is the one open item left from `docs/ROADMAP.md` §6c's
> Intake+ wishlist — everything else there (operator UI, branding, analytics,
> virus-scan-on-receipt) already shipped, per `docs/INTAKE-BRANDING-ANALYTICS-DESIGN.md`.
> The feature: an operator who already has both `intake` and `webhooks` licensed
> gets a signed HTTP POST the instant a client drops a file into their portal,
> instead of having to poll `GET /api/fm/intake/analytics` or the file list.

## 1. Problem & who pays

Today, the only way an operator learns a file landed in a portal is polling.
For a client-onboarding or photographer-delivery workflow ("tell me the moment
the client uploads the signed contract / raw files"), that's the whole point
of the feature — Slack/email/CRM automation via Zapier/Make/n8n needs a push,
not a poll.

Who pays: this needs **both** `intake` (Pro) and `webhooks` (Studio) licensed.
It is not a new SKU — it's the payoff for owning both, consistent with
`docs/COMMERCIAL-STRATEGY.md`'s à-la-carte framing for Webhooks. An operator
with only Intake (no Webhooks) gets nothing extra and pays nothing extra; the
feature is silently absent, never a broken/error state.

## 2. Architecture fit

FluxFiles is stateless: no central DB, JWT claims are the only per-tenant
config, except where a public/unauthenticated route needs config a JWT can't
safely carry — that's exactly the fork point this feature sits on, and it's
already been decided once for Intake:

- **`allow_virus_scan`** (boolean, no secret) is forwarded straight into the
  portal JWT (`IntakeModule::createPortal()`, `packages/intake/src/IntakeModule.php:106-108`)
  so `PublicLinks.php` can wire the fail-closed scanner per-request. Safe,
  because there's nothing to steal in a boolean.
- **`brand` / `analytics`** (an object + a boolean, still no secret, but
  intentionally operator-only rather than sender-facing) are instead **baked
  into the stored `_fluxfiles/intakes.json` record** at create time
  (`IntakeModule.php:127-128`) and read back per-request via `getRecord()`.

Webhook config is neither: `webhook_secret` is a live HMAC signing key. JWTs
are base64url — signed, not encrypted — so anything in the payload is
plaintext to whoever holds the token. The portal token is handed to
**every anonymous sender** as a literal `?token=` query param
(`createPortal()` return `url`, `IntakeModule.php:143-146`). Forwarding
`webhook_secret` into it the way `allow_virus_scan` is forwarded would leak
the operator's signing secret to every visitor of the public upload page —
the exact class of mistake CLAUDE.md already calls out for BYOB credentials
("Never expose storage credentials... encrypted in JWTs and decrypted only at
runtime"). Webhook config gets the same treatment BYOB gets, minus the
encryption (it doesn't need to survive being handed to the public at all —
it just needs to never be handed to the public in the first place):

**Decision: bake `webhook_url` / `webhook_events` / `webhook_secret` into the
stored intake record at `createPortal()` time, mirroring `brand`/`analytics`,
never the portal JWT.**

No new JWT claim is introduced. The bake-in reads the operator's own
already-documented `allow_webhooks` / `webhook_url` / `webhook_events` /
`webhook_secret` claims (`docs/CONFIG.md:196-199`) at the moment they mint a
portal — same "config = the claims on the token doing the minting, snapshotted
at creation" trade-off `max_mb`/`allowed_ext`/`brand` already accept. An
operator who changes their webhook URL only affects portals minted after the
change. No new `docs/CONFIG.md` rows are needed (Part C below is a no-op,
stated explicitly rather than silently skipped).

## 3. Storage layout

`_fluxfiles/intakes.json` record gains one field, set once at create time,
immutable after (matches `brand`/`analytics`):

```jsonc
{
  "jti": "…", "disk": "local", "path": "clients/acme", "store": "u123/",
  "owner": "u123", "label": "Acme intake", "created": 1755600000,
  "expires": 1756800000, "max_files": 0, "max_mb": 50, "allowed_ext": null,
  "received": 3, "rejected": 0,
  "brand": null, "analytics": true, "password_hash": null,
  "webhook": {                          // NEW — null when not configured
    "url": "https://hooks.example.com/x",
    "events": [],                        // [] = fire on every fired event (unfiltered)
    "secret": "whsec_…"
  }
}
```

`webhook` is `null` whenever the operator's own token lacked
`allow_webhooks` or had an empty `webhook_url` at create time — same
"absent means off" posture as `brand: null`. Existing portals created before
this ships simply don't have the key at all; read as `$rec['webhook'] ?? null`,
so old records behave exactly as `null` (see §8, Backward compatibility).

## 4. Write path — `IntakeModule::createPortal()`

`packages/intake/src/IntakeModule.php:112-131` (the `$record` array) gains one
line, built the same way `brand`/`analytics` already are two lines above it:

```php
$record = [
    // …unchanged fields…
    'brand'         => $claims->intakeBrand,
    'analytics'     => $claims->intakeAnalytics,
    'webhook'       => ($claims->allowWebhooks && $claims->webhookUrl !== '')
        ? ['url' => $claims->webhookUrl, 'events' => $claims->webhookEvents, 'secret' => $claims->webhookSecret]
        : null,
    'password_hash' => …,
];
```

No `ModuleRegistry::installed('webhooks')` / license check happens here —
same reasoning as `brand`/`analytics` not checking anything either: baking in
config is free and side-effect-free; the module gate is what actually costs
something (a network call at dispatch time), so it's checked lazily, once,
right before that call (§5). This also means a license that lapses between
portal creation and a later upload fails safe: the bake-in still has the URL,
but dispatch no-ops instead of firing.

## 5. Read path — dispatch on receipt

### 5.1 Where dispatch does *not* happen

`IntakeModule::receiveUpload()` already has a natural-looking hook: right
after a successful upload, if `$reserved['analytics']` is set, it appends a
`received` event in the same function
(`packages/intake/src/IntakeModule.php:274-280`). It would be tempting to
fire the webhook from the same spot using `$reserved['webhook']`.

**Rejected**, for one concrete reason: that call happens *before*
`PublicLinks.php` echoes the JSON response to the anonymous sender.
`WebhooksModule::dispatch()` makes a real HTTP POST with a multi-second
timeout; blocking the sender's own upload response on a third-party
endpoint's response time is exactly the latency FluxFiles' *own* main-flow
webhook wiring already avoids — `packages/core/api/index.php:401-405` calls
`fastcgi_finish_request()` and flushes the response **before** invoking
`$webhookDispatcher(...)`. An anonymous portal visitor has even less reason
to eat that latency than an authenticated caller does. Dispatch must happen
at the same layer and the same point in the request lifecycle as the
main-flow wiring: after the response is sent, in `PublicLinks.php`, not
inside `IntakeModule`.

A second reason to keep it out of `IntakeModule`: cross-module coupling.
`IntakeModule` (package `intake`) has no reference to `WebhooksModule`
(package `webhooks`) anywhere today, and the existing precedent for one paid
module needing another module's capability is core-mediated injection, not a
direct call — `PublicLinks.php` builds the virus scanner closure and hands it
to `$portalFm->setVirusScanner()`; `IntakeModule` never touches
`\FluxFiles\Virus\VirusScanModule` directly
(`packages/core/api/PublicLinks.php:111-123`). Webhook dispatch follows the
same seam: core owns cross-module orchestration.

### 5.2 New read-only method — `IntakeModule::webhookConfigFor()`

```php
/**
 * Read-only: the webhook config baked into a portal's record at create time,
 * plus the identifiers a caller needs to build a dispatch payload. Never
 * throws — a resolve failure (revoked/expired/malformed) just means no
 * webhook fires; the upload has already succeeded and its response has
 * already been sent by the time this is called.
 *
 * @return array{owner:string,label:string,disk:string,path:string,url:string,events:string[],secret:string}|null
 */
public function webhookConfigFor(DiskManager $disks, string $disk, string $store, string $jti): ?array
{
    try {
        $rec = $this->getRecord($disks->disk($disk), $store, $jti);
    } catch (\Throwable $e) {
        return null;
    }
    $wh = $rec['webhook'] ?? null;
    if ($rec === null || !empty($rec['revoked']) || $wh === null) {
        return null;
    }
    return [
        'owner'  => (string) ($rec['owner'] ?? ''),
        'label'  => (string) ($rec['label'] ?? ''),
        'disk'   => (string) $rec['disk'],
        'path'   => (string) $rec['path'],
        'url'    => (string) $wh['url'],
        'events' => (array) ($wh['events'] ?? []),
        'secret' => (string) ($wh['secret'] ?? ''),
    ];
}
```

`getRecord()` (`IntakeModule.php:579`) is already `private`; this method is
the one narrow `public` seam onto it, deliberately shaped so it can never leak
`password_hash` or any other record field — only what a webhook dispatch
needs. It does **not** use `resolveToken()` (that throws on revoked/expired,
which is correct for `portalInfo()`/`receiveUpload()` but wrong here — a
notify failure must never surface as an error after the response is gone).

### 5.3 `PublicLinks.php` — the dispatch call

Exact insertion point, `packages/core/api/PublicLinks.php:104-127` (the
`POST /api/fm/intake/upload` branch):

```php
$payload = \FluxFiles\JwtCompat::decode($token, $secret);           // already there, line 104
// …
$portalClaims = \FluxFiles\Claims::fromJwtPayload($payload);        // already there, line 108
$portalFm = new FileManager($dm, $portalClaims, new StorageMetadataHandler($dm));
$portalFm->setStreamSecret($secret);
if ($portalClaims->allowVirusScan) { /* …unchanged… */ }

$res = $module->receiveUpload($portalFm, $dm, $secret, $token, $file, $password);
echo json_encode(['data' => $res, 'error' => null]);

// Notify-on-receipt: fired AFTER the response is on the wire, same ordering
// as the main flow's webhook dispatch (index.php:401-405). Best-effort —
// WebhooksModule::dispatch() never throws, but this is wrapped anyway since
// nothing downstream of this point can change the response that was just sent.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
try {
    if (\FluxFiles\ModuleRegistry::installed('webhooks')
        && \FluxFiles\LicenseManager::fromEnv()->licensed('webhooks')) {
        $jti = (string) ($payload->jti ?? '');
        $store = (string) ($payload->store ?? '');
        $disk = $portalClaims->allowedDisks[0] ?? '';
        $wh = $module->webhookConfigFor($dm, $disk, $store, $jti);
        if ($wh !== null) {
            $webhooks = new \FluxFiles\Webhooks\WebhooksModule();
            $c = new \FluxFiles\Claims($wh['owner'], [], [], '', 0, null, 0);
            $c->webhookUrl = $wh['url'];
            $c->webhookEvents = $wh['events'];
            $c->webhookSecret = $wh['secret'];
            $webhooks->dispatch($c, $secret, 'intake_received', [
                'disk'         => $wh['disk'],
                'path'         => $wh['path'],
                'name'         => is_array($res) ? (string) ($res['name'] ?? '') : '',
                'portal_label' => $wh['label'],
                'portal_jti'   => $jti,
            ]);
        }
    }
} catch (\Throwable $e) {
    error_log('[fluxfiles] intake notify-on-receipt failed: ' . $e->getMessage());
}
return;
```

**Manual `installed()` + `licensed()` checks, not `ModuleRegistry::require()`.**
`require()` (`ModuleRegistry.php:83`) exists specifically to *throw* — 501 /
402 / 403 — when a gate fails, which is exactly right for a route that must
refuse to proceed (e.g. the virus scanner closure, which is allowed to fail
the upload). It is wrong here: by the time this code runs, the response has
already been echoed and (when available) flushed. A thrown `ApiException`
at this point cannot become an HTTP error response anymore — it would just
be swallowed or logged strangely. Matches the existing precedent at
`packages/core/api/index.php:321-324`, which gates the *main-flow* webhook
dispatcher the same manual way for the same reason (it's also best-effort,
also post-response).

**Synthetic `Claims`.** `WebhooksModule::dispatch(Claims $claims, …)` has no
lower-level entry point that takes raw parameters — it reads
`$claims->webhookUrl` / `webhookEvents` / `webhookSecret` / `userId` directly.
All of `Claims`'s properties are `public`, and its constructor only requires
the first few positional args
(`userId, permissions, allowedDisks, pathPrefix, maxUploadMb, allowedExt, maxStorageMb`,
`Claims.php:296`) — the rest are optional/default. Building a minimal instance
and assigning the three webhook fields afterward is the same technique the
class already supports for any caller that has config from a source other
than a live JWT; nothing in `Claims` or `WebhooksModule` needs to change.

### 5.4 Event name & payload

New event name: **`intake_received`** — not `upload`. `index.php`'s
`resolveAuditAction()` (`index.php:973-1009`) maps authenticated-route URIs to
an existing vocabulary (`upload, rename, delete, ai_tag, …`); none of those
represent "an anonymous stranger sent me a file through a public portal," and
conflating it with `upload` would make an operator's `webhook_events` filter
unable to tell their own uploads from portal receipts. `intake_received`
matches the vocabulary `IntakeModule` already uses internally for its own
analytics events (`type: 'received'`, `IntakeModule.php:280`) and the
ROADMAP's own wording ("notify-on-receipt").

It still respects `webhook_events` filtering exactly like every other event —
`WebhooksModule::dispatch()`'s existing filter check (`webhookEvents !== []`)
runs unmodified. An operator sets `webhook_events: ['intake_received']` on
the token they use to create portals if they want *only* portal-receipt
notifications and nothing from their own CRUD activity at the same URL; empty
(default) fires both.

Payload shape (via `WebhooksModule::payload()`'s existing merge):

```json
{
  "event": "intake_received",
  "timestamp": 1755600042,
  "user": "u123",
  "disk": "local",
  "path": "clients/acme",
  "name": "contract-signed.pdf",
  "portal_label": "Acme intake",
  "portal_jti": "3f9a1c2b8e7d4f10a2b3c4d5"
}
```

`user` is the **portal owner** (`$rec['owner']`), not the anonymous sender —
there is no sender identity to report; that's the entire point of Intake.
`name` is the file's actual stored name (post-dedup-suffix, from
`$res['name']`), not the raw submitted filename.

## 6. Security considerations

- **Secret never reaches the public.** `webhook_secret` only ever exists in
  (a) the operator's own authenticated JWT, (b) the stored
  `_fluxfiles/intakes.json` record (operator-owned storage, same protection
  as `password_hash`/`brand`), and (c) the synthetic `Claims` built
  server-side for the `dispatch()` call. It is never serialized into the
  portal JWT, never present in any response `PublicLinks.php` echoes.
- **Fail-open, not fail-closed.** Unlike virus scanning (which must block an
  unscannable upload — CLAUDE.md: "a malformed verdict counts as infected"),
  a broken webhook endpoint must never affect the upload outcome. The dispatch
  runs strictly after the response is sent; the `try/catch` around it and
  `WebhooksModule::dispatch()`'s own internal catch-all are both belt-and-
  suspenders for the same invariant, not redundant paranoia — either one
  failing to hold would regress a public, anonymous-facing endpoint's
  reliability.
- **SSRF.** Unchanged — `WebhooksModule::dispatch()` already runs every URL
  through `SsrfGuard::assertSafeUrl` + the post-connect
  `assertConnectedIpSafe` re-check, same as the main-flow dispatch. Nothing
  new to guard: the URL still comes from the operator's own claim, never from
  anything the anonymous sender controls.
- **No new attack surface from the anonymous sender.** The sender influences
  only `name` (and indirectly `disk`/`path`, but those are the portal's own
  fixed destination, not attacker-chosen) in the outbound payload — the same
  filename that already flows into the JSON response the sender itself
  receives. Nothing sender-controlled reaches the webhook that doesn't already
  reach the sender's own response.
- **No audit log entry.** Consistent with the rest of `PublicLinks.php`,
  which has zero audit logging on any public Share/Intake route today — a
  pre-existing gap this feature doesn't widen or fix.

## 7. Module-gate independence

`intake` and `webhooks` are separate paid modules/claims/licenses
(`docs/COMMERCIAL-STRATEGY.md`: Intake is Pro, Webhooks is Studio à-la-carte).
An operator can hold either without the other:

| Operator has | Behavior |
|---|---|
| Intake only | Portals work exactly as before; `webhook` bakes as `null` (their token has no `allow_webhooks`); no dispatch attempted. |
| Intake + Webhooks, but `webhook_url` unset | Same as above — `webhook` bakes `null`. |
| Intake + Webhooks, both configured | Bakes in; dispatch fires on receipt. |
| Intake + Webhooks configured, license lapses later | Old portals keep their baked `webhook` config, but `webhookConfigFor()`'s caller re-checks `installed()`/`licensed()` on every receipt — a lapsed license silently stops notifying, same as it silently stops the main-flow dispatcher (`index.php:321-324` re-checks per-request too, nothing cached). |
| Webhooks only (no Intake) | Irrelevant — Intake's own module gate (`allow_intake`) already blocks portal creation entirely; this feature is unreachable. |

## 8. Backward compatibility

Portals created before this ships have no `webhook` key in their stored
record at all. `$rec['webhook'] ?? null` in `webhookConfigFor()` treats a
missing key exactly like an explicit `null` — no dispatch, no error, no
migration needed. This is the same posture `brand`/`analytics` already
established for portals that predate *that* feature
(`docs/INTAKE-BRANDING-ANALYTICS-DESIGN.md` §8).

No wire format changes to `portalInfo()`'s public response (§2's `brand`-only
precedent holds: webhook config is operator-only, never sender-facing) and no
change to `receiveUpload()`'s response shape (`{received, name, remaining}`
unchanged) — the notify path is entirely additive and invisible to the
anonymous sender.

## 9. Package layout

- **`packages/intake/src/IntakeModule.php`** — one new field in
  `createPortal()`'s `$record` (§4); one new public method,
  `webhookConfigFor()` (§5.2). No change to `receiveUpload()`'s signature or
  body.
- **`packages/core/api/PublicLinks.php`** — the dispatch call added to the
  `POST /api/fm/intake/upload` branch, after the existing `echo` (§5.3). This
  is a **core** change (free/MIT), same as the virus-scan wiring already in
  the same function — core owns the cross-module orchestration seam.
- **`packages/webhooks/src/WebhooksModule.php`** — **no changes.**
  `dispatch()`'s existing signature already accepts everything this feature
  needs.
- **`packages/core/api/Claims.php`** — **no changes.** All fields used
  (`allowWebhooks`, `webhookUrl`, `webhookEvents`, `webhookSecret`,
  `intakeBrand`-style bake-in pattern) already exist.

## 10. Test plan

- **`packages/intake/tests/test-intake.php`** (unit/integration, gitignored
  package's own test file):
  - `createPortal()` with `allow_webhooks=true` + `webhook_url` set on the
    minting token → stored record has a `webhook` object with the right
    `url`/`events`/`secret`; portal JWT itself does **not** contain
    `webhook_url`/`webhook_secret` anywhere (decode the returned token,
    assert the keys are absent) — this is the one assertion that most
    directly guards the security decision in §2.
  - `createPortal()` with `allow_webhooks=false` (or empty `webhook_url`) →
    stored record's `webhook` is `null`.
  - `webhookConfigFor()` against a revoked/expired/nonexistent
    disk+store+jti → returns `null`, never throws.
  - `webhookConfigFor()` against a valid portal with `webhook` baked →
    returns the expected bundle (`owner`/`label`/`disk`/`path`/`url`/`events`/`secret`).
  - A record from before this feature (no `webhook` key at all) →
    `webhookConfigFor()` returns `null` (backward-compat, §8).
- **New self-booting e2e**, e.g. `tests/e2e/test-intake-notify-http.php`
  (pick an unused port per the CLAUDE.md list — 8113 or next free), modeled on
  the existing `test-webhooks-http.php`/`test-intake-*-http.php` style
  (self-boots `php -S`, spins up a tiny local HTTP listener to catch the
  webhook POST, mints a token with `allow_intake` + `allow_webhooks` +
  `webhook_url` pointed at the local listener):
  - Create a portal, upload a file through it → assert the local listener
    received exactly one POST, with `event: "intake_received"`, a valid
    `X-FluxFiles-Signature` HMAC over the body using the configured secret,
    and the expected `name`/`portal_label`/`portal_jti` fields.
  - Create a portal with `webhook_events: ['upload']` (i.e. filtering OUT
    `intake_received`) → upload → assert **no** POST arrives.
  - Create a portal without `allow_webhooks` on the minting token → upload →
    assert no POST arrives and the upload's own JSON response is unaffected
    (still `{received: true, ...}` with 200-level behavior).
  - Simulate the webhook endpoint being down/unreachable → assert the
    upload's HTTP response is still 200 with the normal body (fail-open, §6).
- No changes needed to `tests/unit/test-config-doc.php`'s expectations —
  §11 below confirms no new claims are added, so nothing new needs
  documenting there.

## 11. Part C — `docs/CONFIG.md` additions

**None.** This feature introduces zero new JWT claim names. It reads the
four already-documented webhook claims (`docs/CONFIG.md:196-199`) at a new
*point in time* — `IntakeModule::createPortal()`, in addition to their
existing per-request read in `index.php`'s main flow — not with any new
name or new semantics. Stated explicitly here (rather than silently
omitting a Part C) so a future reader doesn't wonder whether this was missed.

## 12. Part D — composer floor bump

**Not needed for either package.** The adapter/core-floor rule
(`[[adapter-core-version-constraint]]`) exists for the case where a package
starts calling a core method or reading a core claim that didn't exist at its
currently-declared floor. Checking both:

- `packages/intake/composer.json` currently floors at `^0.2.76`. The new
  `webhookConfigFor()` method reads `Claims::$allowWebhooks` /
  `$webhookUrl` / `$webhookEvents` / `$webhookSecret` — all of which already
  existed at `packages/webhooks/composer.json`'s own floor of `^0.2.74`,
  i.e. two core releases *before* `intake`'s current floor already had them.
  `IntakeModule` also calls `ModuleRegistry::installed()` — long-standing,
  used throughout the package already. Nothing in this feature reads a core
  API introduced after core-v0.2.76.
- `packages/webhooks/composer.json` (`^0.2.74`) — no changes to
  `WebhooksModule` at all (§9); nothing to bump.

The only actual code change to **core** is `PublicLinks.php` (§5.3), which
means a new core tag ships this feature, same as any core change — but
neither paid package's own floor needs raising to consume it, since neither
package's code depends on anything new in that core release.

## Summary: exact claims involved

| Claim | Status | Where read for this feature |
|---|---|---|
| `allow_webhooks` | existing, unchanged | `IntakeModule::createPortal()` — gates whether `webhook` bakes into the record |
| `webhook_url` | existing, unchanged | same |
| `webhook_events` | existing, unchanged | same; also respected unmodified at dispatch time |
| `webhook_secret` | existing, unchanged | same |
| `allow_intake` | existing, unchanged | unaffected — still gates portal creation entirely, independent of the above |

No claim is added, renamed, or changed in meaning. `docs/CONFIG.md` needs no
edits for this feature.
