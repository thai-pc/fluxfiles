# Share Analytics — Per-Event Log for the Share Module

Status: **Implemented and shipped.** `share_analytics` claim, per-event JSONL
log (`_fluxfiles/share-events/<jti>.jsonl`), and `GET /api/fm/share/analytics`
all live in `packages/share/src/ShareModule.php` and `Claims::$shareAnalytics`.
See the CHANGELOG "Share per-event analytics" entry (`share`'s composer floor
bumped to `^0.2.75`). All four token builders now give it named forwarding —
`packages/core/embed.php`, `packages/node/src/token.ts` (+ `types.ts`'s
`shareAnalytics` option), `FluxFilesManager.php`, and `FluxFilesPlugin.php` —
closing a gap where embed.php/Node initially shipped with only the generic
raw-claims escape hatch while Laravel/WordPress got explicit plumbing.

> Scope note: this spec is about **analytics only**. A separate, already-implemented
> feature ("Branded Share" — `share_brand_*` claims, `Claims::sanitizeShareBrand()`,
> `$rec['brand']` in `ShareModule.php`) touches the exact same three methods this spec
> touches (`createShare()`, `card()`, `shareInfo()`). It is not designed or re-described
> here beyond one compatibility note in §2 — see the record-assembly block in
> `createShare()`, which already carries `url_ttl` / `preview` / `brand` as sibling keys;
> this spec adds `analytics` as one more sibling key in the same array literal. No
> structural conflict.

## 1. Problem & who pays

Share is the flagship paid SKU (`packages/share/src/ShareModule.php`, gitignored
private package, gated by `ModuleRegistry` — installed + licensed + `allow_share`).
Today an operator gets three aggregate counters per share (`views`, `downloads`,
`unlock_fails`) and nothing else: no "who opened this link, when, from where."
That's a real gap for the persona who actually buys Share — an agency/freelancer
sending client deliverables, or a support team using it for one-off exchanges — who
wants to know *"did the client actually open it, and when."*

This is a **deepening of the existing paid module**, not a new module and not a new
free/core feature. It ships inside `packages/share/`, gated by the same `allow_share`
claim as everything else in Share. No new SKU, no new pricing tier — this is a
capability upgrade to the module operators are already paying for (see the gitignored
`docs/COMMERCIAL-STRATEGY.md`: Share is the one hero SKU worth investing in further).

## 2. Architecture fit

Stays fully storage-resident, no DB, no scheduler — same grain as the rest of Share:

- The **aggregate counters** (`views`/`downloads`/`unlock_fails`) already live in
  `<prefix>/_fluxfiles/shares.json`, one JSON blob per tenant, guarded by
  `ShareModule::withLock()` (flock on the local driver, best-effort elsewhere).
- The **new per-event log** is a second, additive artifact: one JSONL file **per
  share** at `<prefix>/_fluxfiles/share-events/<jti>.jsonl` (§5). It is written
  **inside the same `withLock()` critical section** that already bumps the
  aggregate counter for that request — no second lock, no new race window (§6).
- Whether logging happens at all for a given share is **baked into the share record
  at create time**, exactly like `url_ttl` / `preview` / `brand` already are — a
  public request (the recipient opening the link) carries no JWT/claims, only the
  signed share token + the stored record, so policy has to travel with the record.
- No cron, no background pruning job. Old events are deleted opportunistically, on
  a write that was already happening for another reason (§5) — the same trade-off
  `shares.json` itself already accepts for tombstone pruning.

Compatibility with Branded Share (already implemented, not part of this spec): its
`'brand' => $claims->shareBrand` sits in the same record-assembly array literal in
`createShare()` as `url_ttl`/`preview`. This spec adds `'analytics' => $claims->shareAnalytics`
as one more key in that same literal — pure sibling addition, no reordering needed.

## 3. JWT claims

**New claim: `share_analytics`** (bool, default `false`).

Decision: **gate it behind its own opt-in claim, not just `allow_share`.** The
existing aggregate counters carry no personal data (three integers). This feature
persists a **timestamped, per-visitor IP address + User-Agent string** for every
anonymous open of a link — that is squarely the kind of thing operators need
explicit control over for privacy/compliance reasons (GDPR "personal data"), the
same category of decision that made `allow_url_import` default `false` (an
opt-in for a feature with a real footprint) rather than default `true` like
`media_preview` or `webp_enabled` (zero compliance cost, on by default). An
operator who cannot legally log visitor IPs for a given tenant must be able to
keep the module's core value (link + expiry + cap + password) while leaving
event logging off — a single `allow_share` gate would force an all-or-nothing
choice. Turning `share_analytics` on/off going forward only affects **shares
created after the change** (identical, already-accepted trade-off to `url_ttl` /
`preview` / `brand`, all baked in at create time).

Add to `docs/CONFIG.md` §2.13 ("Paid-module gates"), as a new row directly **after**
the existing `share_preview` row and **before** `share_brand_name`:

```
| `share_analytics` | bool | `false` | Log a per-event record (timestamp, view/download/unlock_fail, IP, user-agent) for a share, in addition to the existing `views`/`downloads`/`unlock_fails` counters. Off by default — this persists visitor IP addresses (privacy/compliance footprint), unlike the plain counters. Baked into the share record at create time, like `share_url_ttl`. |
```

No other new claims. Rotation/retention sizing (§5) is intentionally **not** a claim
— hardcoded constants, mirroring `AuditLogStorage::MAX_AUDIT_BYTES` /
`AUDIT_KEEP_LINES`, which aren't tenant-configurable either. Keeping the claim
surface to one boolean avoids claim bloat for a feature whose only real per-tenant
decision is "log or don't."

### `Claims.php` changes (core, not gitignored)

Next to `$sharePreview` (`packages/core/api/Claims.php:187`):

```php
/** @var bool Log a per-event view/download/unlock_fail record (timestamp + IP +
 *            user-agent) for a share, alongside the existing aggregate counters.
 *            Off by default — persists visitor IPs (privacy/compliance footprint).
 *            Baked into the share record at create time, like sharePreview. */
public bool $shareAnalytics = false;
```

In `fromJwtPayload()`, right after the existing `$c->sharePreview = ...` line
(`Claims.php:512`):

```php
$c->shareAnalytics = isset($payload->share_analytics) ? (bool) $payload->share_analytics : false;
```

## 4. Endpoints

One new **operator-authed** endpoint, nested under `/share` like the existing
`list`/`revoke` management routes (never public — the recipient never sees this):

**`GET /api/fm/share/analytics?disk=local&jti=<id>&limit=100&offset=0&event=view|download|unlock_fail`**

- Auth: main JWT, same 3-layer gate as create/list/revoke —
  `ModuleRegistry::require('share', LicenseManager::fromEnv(), $claims)` (501 if
  not installed, 402 if unlicensed, 403 `module_forbidden` if `allow_share` is off).
- Ownership: same rule as `revokeShare()` — under `owner_only`, only the share's
  creator may read its analytics (`403 perm_denied` otherwise). Unlike `list`,
  which silently filters, analytics is a **single-share lookup**, so a
  non-owner request fails loudly rather than returning an empty page.
- `limit` clamped to `[1, 500]` (default 100); `offset >= 0`; `event` optional
  exact-match filter on `view`/`download`/`unlock_fail`.
- Response:

```json
{
  "jti": "a1b2c3d4e5f60718293a4b5c",
  "analytics_enabled": true,
  "total": 3,
  "events": [
    { "ts": 1755500000, "type": "download", "ip": "203.0.113.7", "ua": "Mozilla/5.0 (...)" },
    { "ts": 1755499800, "type": "view",     "ip": "203.0.113.7", "ua": "Mozilla/5.0 (...)" },
    { "ts": 1755498000, "type": "unlock_fail", "ip": "198.51.100.4", "ua": "curl/8.4.0" }
  ]
}
```

  `analytics_enabled` distinguishes "this share was created with logging off" from
  "logging is on but nothing happened yet" — the operator UI needs that to explain
  an empty list correctly instead of implying a bug.

- Errors (both **reused**, no new error codes / no new i18n strings needed — see §8):
  `404 share_revoked` (unknown jti — same "not found and revoked are one answer"
  posture as the public routes' `resolveToken()`), `403 perm_denied` (not the owner).

### `index.php` wiring

Directly after the existing `/api/fm/share/revoke` block
(`packages/core/api/index.php:657-661`):

```php
if ($method === 'GET' && $uri === '/api/fm/share/analytics') {
    $module = \FluxFiles\ModuleRegistry::require('share', \FluxFiles\LicenseManager::fromEnv(), $claims);
    return $module->analytics(
        $diskManager,
        $claims,
        ff_str_param($_GET, 'disk', 'local'),
        ff_str_param($_GET, 'jti'),
        max(1, min(500, (int) ($_GET['limit'] ?? 100))),
        max(0, (int) ($_GET['offset'] ?? 0)),
        isset($_GET['event']) && $_GET['event'] !== '' ? ff_str_param($_GET, 'event') : null
    );
}
```

Core-standalone, like the rest of Share's management routes — not proxied by the
Laravel/WordPress adapters (they don't proxy `/api/fm/share*` today either).

## 5. Storage layout

**One JSONL file per share** (not one per tenant, unlike `shares.json`):

```
<prefix>/_fluxfiles/share-events/<jti>.jsonl
```

`<jti>` is the share's own id (`bin2hex(random_bytes(12))` — 24 lowercase hex
chars, already filename-safe; validate with the same
`preg_match('/^[A-Za-z0-9_-]{8,64}$/', $jti)` pattern `ff_share_token_jti()`
already uses, as defense-in-depth against a malformed jti ever reaching the path).

Why per-share rather than one tenant-wide log (the `audit.jsonl` shape): a read
only ever wants one share's history, so a per-share file means `analytics()`
reads exactly the bytes it needs — no tenant-wide scan-and-filter like
`AuditLogStorage::list()` has to do across every disk. It also gives natural,
free-standing lifecycle: the file can be deleted outright when its share is
fully pruned (below), instead of being a filtered-out subset of a shared blob.

**JSON-lines schema**, one line per event:

```json
{"ts": 1755500000, "type": "view", "ip": "203.0.113.7", "ua": "Mozilla/5.0 (...)"}
```

- `ts` — int, unix seconds.
- `type` — `"view"` | `"download"` | `"unlock_fail"` (mirrors the three existing
  aggregate counter names exactly, so the UI can reuse one vocabulary).
- `ip` — string, validated (`filter_var(..., FILTER_VALIDATE_IP)`), or `""` if
  `REMOTE_ADDR` is missing/invalid — never a raw unvalidated value.
- `ua` — string, control-characters stripped + length-capped (§8).

**Rotation** (mirrors `StorageMetadataHandler`'s audit rotation, scaled down to
per-share granularity — new constants in `ShareModule.php`):

```php
private const EVENTS_DIR        = '_fluxfiles/share-events';
private const EVENTS_MAX_BYTES  = 1 * 1024 * 1024; // 1MB per-share rotation threshold
private const EVENTS_KEEP_LINES = 2000;            // keep last N events after rotation
private const ANALYTICS_MAX_LIST = 500;            // per-request read cap (mirrors AuditLogStorage::MAX_LIST)
```

Same read-modify-write-with-truncation shape as
`StorageMetadataHandler::audit()`: on each append, if the current file exceeds
`EVENTS_MAX_BYTES`, keep only the last `EVENTS_KEEP_LINES` lines before
appending the new one. A single share's public rate limits
(`FLUXFILES_SHARE_RATE_LIMIT` = 60/min for views, the two unlock buckets for
failed attempts) already bound how fast this file can grow; the cap exists for
the pathological case (a link open in a loop for the full 30-day `MAX_TTL`).

**Cleanup on revoke/expiry** — piggybacks on work `ShareModule::save()` already
does, no new trigger:

- `revokeShare()` already overwrites a share's record with a tombstone
  (`['jti', 'revoked', 'expires']`) rather than deleting it outright — extend
  this to **also keep `owner`**: `['jti', 'revoked', 'expires', 'owner']`. This
  is the one small change to existing revoke behavior this spec requires: without
  it, `analytics()`'s `owner_only` check (§4) has nothing to check against once a
  share is revoked, since the full record (which carried `owner`) is gone. Adding
  a field to the tombstone doesn't change any existing check — `resolveToken()`
  and friends only ever look at `revoked`/`expires` on a tombstone.
- `save()` already prunes tombstones whose `expires` has passed (so the JWT that
  named them can no longer be presented anyway). Extend that same loop to also
  delete `_fluxfiles/share-events/<jti>.jsonl` for each pruned jti (best-effort,
  wrapped like every other write in this class). This is the *only* cleanup
  trigger — there is no cron in this architecture, so events for a share that is
  **never explicitly revoked** (just left to expire naturally) persist
  indefinitely, exactly like `shares.json`'s own record does today for a
  non-revoked expired share. Not a regression this feature introduces — an
  existing, already-accepted property of the store, called out again in §11.

## 6. Write path (in `ShareModule.php`)

Three existing call sites already bump a counter under `withLock()`/`mutateRecord()`.
Add the event write **inside the same locked critical section**, gated on the
record's own baked-in `analytics` flag — not a fresh lock, not a race with the
counter write, because both happen in one critical section:

1. **`shareInfo()`** (line ~167) — bumps `views` on every landing-page load
   (including a still-password-gated one). Event type: `"view"`.
2. **`unlockShare()`** (line ~201) — bumps `unlock_fails` on a wrong password.
   Event type: `"unlock_fail"`.
3. **`resolveShare()`** (line ~267) — bumps whichever of `downloads`/`views` the
   `$isDownload` flag selects. Event type: `"download"` or `"view"` to match.

Implementation: extend `mutateRecord()`'s signature with an optional event type,
and append inside the same closure that already holds the lock and just wrote
`shares.json`:

```php
private function mutateRecord(
    DiskManager $disks, string $disk, string $prefix, string $jti,
    callable $mutator, ?string $event = null
): array {
    return $this->withLock($disks, $disk, $prefix, function () use ($disks, $disk, $prefix, $jti, $mutator, $event) {
        $fs = $disks->disk($disk);
        $all = $this->load($fs, $prefix);
        $rec = $all[$jti] ?? null;
        if ($rec === null || !empty($rec['revoked'])) {
            throw new ApiException('Share not found or revoked', 404, 'share_revoked');
        }
        $rec = $mutator($rec);
        $all = $this->load($fs, $prefix); // re-check for a concurrent revoke, unchanged
        if (!empty($all[$jti]['revoked'])) {
            throw new ApiException('Share not found or revoked', 404, 'share_revoked');
        }
        $all[$jti] = $rec;
        $this->save($fs, $prefix, $all);
        // Fast path preserved when off: zero filesystem cost beyond what already
        // happens today, matching the default (analytics claim = false).
        if ($event !== null && !empty($rec['analytics'])) {
            $this->appendEvent($fs, $prefix, $jti, $event);
        }
        return $rec;
    });
}
```

Call sites become one-line additions (the last argument is new):

```php
// shareInfo():
$rec = $this->mutateRecord($disks, $disk, $store, $jti, function (array $rec) {
    $rec['views'] = (int) ($rec['views'] ?? 0) + 1;
    return $rec;
}, 'view');

// unlockShare() (wrong-password branch):
$this->mutateRecord($disks, $disk, $store, $jti, function (array $rec) {
    $rec['unlock_fails'] = (int) ($rec['unlock_fails'] ?? 0) + 1;
    $rec['last_unlock_fail'] = time();
    return $rec;
}, 'unlock_fail');

// resolveShare():
$rec = $this->mutateRecord($disks, $disk, $store, $jti, function (array $rec) use ($isDownload) {
    // ...unchanged cap check + counter bump...
}, $isDownload ? 'download' : 'view');
```

And `createShare()`'s record assembly (line ~119, alongside `url_ttl`/`preview`/`brand`):

```php
'analytics' => $claims->shareAnalytics,   // baked in at create time — see Claims::$shareAnalytics
```

`appendEvent()`:

```php
private function appendEvent($fs, string $prefix, string $jti, string $type): void
{
    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '';
    $ua = $this->cleanLabel($_SERVER['HTTP_USER_AGENT'] ?? '', 200); // control-strip + cap, see §8
    $entry = json_encode(['ts' => time(), 'type' => $type, 'ip' => $ip, 'ua' => $ua]) . "\n";
    $key = self::EVENTS_DIR . '/' . $jti . '.jsonl';
    $key = ($prefix !== '' ? trim($prefix, '/') . '/' : '') . $key;
    try {
        $content = $fs->fileExists($key) ? (string) $fs->read($key) : '';
        if (strlen($content) > self::EVENTS_MAX_BYTES) {
            $lines = array_slice(array_filter(explode("\n", $content)), -self::EVENTS_KEEP_LINES);
            $content = implode("\n", $lines) . "\n";
        }
        $fs->write($key, $content . $entry);
    } catch (\Throwable $e) { /* best-effort, like every other write in this class */ }
}
```

(`cleanLabel()` gains an optional `int $max = 120` parameter so it can be reused
here at `200` instead of its current hardcoded `120` — a one-line signature
change, no behavior change at existing call sites.)

## 7. Read/list semantics

- **Pagination**: `limit` (default 100, clamped `[1, 500]` = `ANALYTICS_MAX_LIST`)
  + `offset` — same shape as `AuditLogStorage::list()`'s `$limit`/`$offset`, same
  reasoning as its `MAX_LIST = 500` (bulk export isn't the use case; "what
  happened recently" is).
- **Sort order**: newest first (`ts` descending), identical to
  `AuditLogStorage::list()`'s `usort(..., fn($a,$b) => $b['created_at'] <=> $a['created_at'])`.
- **Filtering**: optional `event` exact-match on `view`/`download`/`unlock_fail`
  (an operator investigating a suspected brute force wants `event=unlock_fail`
  only). No date-range filter in v1 — the rotation cap (§5) already limits how
  far back a file can reach; add `from`/`to` later if operators ask, mirroring
  `AuditLogStorage`'s `filters['from']`/`filters['to']`.
- No cross-share listing — analytics is always scoped to one `jti`. An operator
  wanting a cross-share overview keeps using `GET /api/fm/share/list` (aggregate
  counters) as the summary table; `analytics` is the drill-down.

## 8. Backward compatibility

- `views` / `downloads` / `unlock_fails` are **unchanged** — same fields, same
  increment logic, same `mutateRecord()` shape, still readable via
  `GET /api/fm/share/list`. This feature is purely additive.
- Default (`share_analytics` unset / `false`): **zero behavior change** — the
  `!empty($rec['analytics'])` check short-circuits before any new file is ever
  touched, so an operator who doesn't opt in sees no new files, no new I/O, no
  new latency on the existing hot paths (`shareInfo`/`unlockShare`/`resolveShare`).
- A share created **before** this feature ships has no `analytics` key in its
  stored record at all; `!empty($rec['analytics'])` treats a missing key exactly
  like `false` — old shares keep working, logging nothing, with no migration step.

## 9. Security considerations

- **Log injection (JSONL corruption via a crafted User-Agent)**: PHP's
  `json_encode()` already escapes control characters (including `\n`/`\r`) as
  part of the JSON string-encoding rules, so a literal newline byte can never
  appear unescaped in `appendEvent()`'s output *as long as the entry is always
  built as a PHP array and passed through one `json_encode()` call* — never
  hand-concatenated. That said, this spec still requires stripping control
  characters and capping length **before** encoding (via `cleanLabel()`, §6),
  as defense-in-depth against (a) a future code path that concatenates instead
  of encoding, and (b) simply keeping stored/displayed data clean — same
  belt-and-braces posture the codebase already applies to `label`
  (`cleanLabel()` on user input that's also always json_encoded downstream).
- **IP validation**: `$_SERVER['REMOTE_ADDR']` is set by the SAPI from the TCP
  peer, not directly attacker-controlled, but is still passed through
  `filter_var(..., FILTER_VALIDATE_IP)` before storage — a proxy misconfigured
  to copy an untrusted header into `REMOTE_ADDR` must not result in an arbitrary
  string landing in the log. Invalid → stored as `""`, never as unvalidated input.
- **UA length cap**: 200 chars (via `cleanLabel($ua, 200)`) bounds both display
  clutter and per-event storage size regardless of what a client sends; combined
  with the rotation cap (§5) this bounds total file growth independent of the
  existing rate limiter.
- **XSS on display**: `ua`/`ip` are stored strings that will eventually be
  rendered in an operator dashboard. This spec's API returns them as JSON only
  (`Content-Type: application/json`, `json_encode` throughout) — any HTML
  rendering of `ua`/`ip` is the UI's responsibility to escape, same as every
  other user-supplied string already surfaced today (`label`, `webhook_url`, …).
  Flagged here so the implementer doesn't assume the API layer already sanitizes
  for HTML context.
- **No SSRF surface**: this feature makes no outbound request (unlike
  `import-url`/`webhooks`/BYOB); `SsrfGuard` is not applicable.
- **Ownership / `owner_only`**: `analytics()` reuses exactly `revokeShare()`'s
  check (`$claims->ownerOnly && ($rec['owner'] ?? null) !== $claims->userId` →
  `403 perm_denied`), which is why §5 extends the tombstone to retain `owner` —
  without it, ownership of a revoked share's history becomes unverifiable and
  the endpoint would have to fail closed (return `404`) for every revoked share
  under `owner_only`, which is worse for the paying operator than a one-field
  tombstone change.
- **Error codes**: **no new error codes and no new i18n strings.** `analytics()`
  reuses `404 share_revoked` (unknown jti — same "not found and revoked are one
  answer on purpose" posture the public routes already use in `resolveToken()`)
  and `403 perm_denied` (not the owner), both already translated in all 16
  `packages/core/lang/*.json` files. This was a deliberate design choice over
  minting `share_not_found`/similar, specifically to avoid new translation work
  for a feature that doesn't need a new user-facing message shape.
- **Public-route unaffected**: none of `handleSharePublic()` /
  `PublicLinks.php`'s response shapes change — `analytics` is never returned to
  a recipient, only to the operator via the new authed endpoint.

## 10. Package layout

**Free/core files touched** (not gitignored — real edits, not spec-only):

- `packages/core/api/Claims.php` — new `public bool $shareAnalytics = false;`
  property + one line in `fromJwtPayload()` (§3).
- `packages/core/api/index.php` — new `GET /api/fm/share/analytics` route (§4).
- `docs/CONFIG.md` — new `share_analytics` row in §2.13 (§3); required or
  `tests/unit/test-config-doc.php` fails CI.

**Private module files touched** (`packages/share/`, gitignored, present locally):

- `packages/share/src/ShareModule.php`:
  - `createShare()` — one new key in the record-assembly literal (§6).
  - `mutateRecord()` — new optional `?string $event` parameter (§6).
  - `shareInfo()`, `unlockShare()`, `resolveShare()` — one new trailing argument
    each at their existing `mutateRecord()` call sites (§6).
  - `revokeShare()` — tombstone gains `owner` (§5).
  - `save()` — tombstone-pruning loop also deletes the pruned jti's events file (§5).
  - `cleanLabel()` — gains an optional `int $max = 120` parameter (§6).
  - New: `appendEvent()` (private, §6) and `analytics()` (public, §4/§7).
  - New constants: `EVENTS_DIR`, `EVENTS_MAX_BYTES`, `EVENTS_KEEP_LINES`,
    `ANALYTICS_MAX_LIST` (§5/§7).

No changes to `packages/core/api/PublicLinks.php` — the public routes
(`handleSharePublic`) are untouched; this is purely an operator-side read/write
addition behind the existing authed router.

## 11. Test plan

**`packages/share/tests/test-share.php`** (engine tests, local pattern already
in the file — add cases alongside the existing `views`/`unlock_fails` tests):

- `createShare()` bakes `analytics` from `$claims->shareAnalytics` into the
  record (both `true` and default `false`).
- With `analytics = false` (default): `shareInfo()`/`unlockShare()`/`resolveShare()`
  bump the aggregate counters exactly as before, and
  `_fluxfiles/share-events/<jti>.jsonl` is **never created** (assert
  `!is_file(...)` after a view/download/failed-unlock cycle) — pins the
  zero-cost-when-off requirement (§8).
- With `analytics = true`: one `view` event on `shareInfo()`, one `unlock_fail`
  event on a wrong `unlockShare()` password, one `download` event on
  `resolveShare($isDownload=true)` — assert the JSONL file's line count and each
  line's `type`/`ip`/`ua` shape.
- A crafted `$_SERVER['HTTP_USER_AGENT']` containing `"\r\nInjected: header"` and
  raw control bytes → the stored `ua` has no literal control characters, the
  events file stays exactly one line for that event (proves no JSONL corruption).
- Rotation: pre-seed `_fluxfiles/share-events/<jti>.jsonl` past
  `EVENTS_MAX_BYTES` directly (`file_put_contents`, same trick the existing
  tombstone-pruning test uses to pre-seed `shares.json`), append one more event,
  assert the file now holds at most `EVENTS_KEEP_LINES + 1` lines and the newest
  event is present.
- `analytics()`: returns events newest-first; `event=` filter narrows correctly;
  `limit`/`offset` clamp and paginate; unknown `jti` → `share_revoked`; a
  non-owner under `owner_only` → `perm_denied`; **the owner still gets
  `perm_denied` correctly rejected for another user's share even after that
  share has been revoked** (exercises the tombstone-retains-`owner` change).
- Revoking a share, then aging its tombstone past `expires` (existing test
  pattern already does this for `shares.json` pruning) and triggering another
  `save()` (e.g. creating a second share) → the pruned jti's events file is
  deleted from disk.

**`packages/core/tests/e2e/test-share-http.php`** (three-phase pattern already
in the file — module-absent / installed-unlicensed / licensed):

- Phase 3 (licensed) only, since this is an operator-authed route requiring a
  real main JWT (phases 1–2 already cover the 501/402 gate generically via the
  existing create/list/revoke routes — no need to repeat per-route): mint a
  share with `share_analytics: true` in `claims`, drive one `GET .../info`
  (view) and one `GET .../file` (download) over real HTTP, then call
  `GET /api/fm/share/analytics?jti=...` with the operator's main JWT and assert
  the two events come back with the right `type`s and a plausible `ip`/`ua`.
  Also assert `analytics_enabled: false` and an empty `events: []` for a share
  created without the claim.

**`packages/core/tests/unit/test-config-doc.php`**: passes once `share_analytics`
is documented in `docs/CONFIG.md` (no code change to this test needed — it's a
generic guard that reads `Claims.php` against `docs/CONFIG.md`).

**Not needed**: no browser/Playwright test — this feature has no public-facing
UI surface (`share.html` is untouched); it's an operator API + (eventually) an
operator dashboard table, which is out of scope for this spec.

## 12. Open questions / trade-offs

- **Never-revoked, naturally-expired shares keep their events file forever** —
  same pre-existing property `shares.json` already has for its own record (§5).
  Not fixed here; flagging in case a future pass wants a lazy "delete on next
  resolveToken() 410" hook for both artifacts together.
- **No server-wide kill switch** (e.g. a `FLUXFILES_SHARE_ANALYTICS_DISABLED` env
  var) was considered, mirroring `FLUXFILES_TERMINAL_DISABLED`. Not proposed
  here because unlike the terminal (which grants a shell), a mis-set
  `share_analytics` claim has a bounded, storage-local blast radius (an extra
  JSONL file with IP/UA in the operator's own bucket) — the per-token claim is
  judged sufficient. Worth revisiting if a multi-tenant SaaS operator ever asks
  for a way to hard-block analytics across every tenant regardless of claims.
- **IP truncation/anonymization** (e.g. zero the last IPv4 octet) was considered
  and deliberately **not** built — it would be a second privacy knob on top of
  the existing off/on one, adding claim surface for a decision `share_analytics`
  already delegates to the operator (if full IPs are a compliance problem, the
  claim stays off). Revisit only if real operator demand shows up.
- **No date-range filter** in v1 (§7) — add `from`/`to` later if requested;
  the rotation cap already bounds lookback in practice.
