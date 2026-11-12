# Intake document requests (step 2) + the notice UI (step 1 completion)

Status: approved for build on 2026-11-12. This continues `INTAKE-SHARE-NOTIFICATIONS-DESIGN.md`. Its §12 reserved the event names used here. Everything stays storage-resident and stateless. There is no queue, no cron inside core, and no new server database.

## 0. Scope

| Part | What ships |
|---|---|
| A. Notice UI (core) | Create dialogs: an "E-mail me" toggle and a recipient picker. Lists: a bell badge with mute/unmute. Links panel: "Send test e-mail". |
| B. Document requests (Intake module + core) | A checklist of requested documents, an optional due date, and an optional client ("requestee") e-mail. Reminders and overdue notices. A "request complete" notice. Per-item subfolders. |
| C. Portal page (`intake.html`) | Renders the checklist. Each upload is tagged to an item. Shows progress and the due date. |
| D. WordPress Pro | An "E-mail notices" settings section: recipients, language, From name, status line, "Send test e-mail". A WP-cron sweep. |
| E. Schedulers | `php artisan fluxfiles:notify-run` (Laravel) and `fluxfiles notify:run` (core CLI), so reminders and expiry notices fire without hand-written HTTP calls. |

Share gets no new features here. It only gets the UI from part A.

## 1. Create body: `request` (Intake `POST /api/fm/intake`)

```jsonc
"request": {
  "items": [ { "label": "Passport (photo page)", "required": true, "ext": ["pdf","jpg"] }, ... ],  // 1..20
  "due_at": 1797000000,            // optional unix ts; must be > now+1h and <= portal expires
  "requestee": "client@example.com", // optional; see §4
  "message": "Hi Anna, please upload…", // optional, <= 1000 chars, plain text, shown on the page + in the request mail
  "send_now": true,                // optional; e-mail the portal link to the requestee immediately
  "subfolders": true               // optional, default true: each item's files land in <dest>/<item folder>/
}
```

Server rules (IntakeModule::createPortal, fail with 400 `intake_request_invalid` + `error_params.field`):
- `items`: 1–20. `label` is trimmed, control characters are stripped, 1–80 chars, and must be unique (case-insensitive). `required` defaults to `true`.
- Each item's `ext` is lowercased and deduplicated, and must be a subset of the portal's effective `allowed_ext` (when that is non-null). An item ext not allowed by the portal is a 400, not a silent drop. Omitted means the portal's list.
- Each item gets a server-generated `id` (`i1`…`i20`, in order). Client ids are ignored.
- `folder` = a slug of the label: `[a-z0-9-]`, ≤ 40 chars, collision-suffixed `-2`, `-3`. It is only used when `subfolders` is on, and is relative to the portal destination.
- Without `request`, the portal behaves exactly as today. This feature is purely additive.

Record additions (plaintext, operator text, like `label`):
```jsonc
"request": { "items": [{"id":"i1","label":"…","required":true,"ext":["pdf"],"folder":"passport-photo-page"}],
             "due_at": 1797000000, "message": "…", "subfolders": true },
"request_state": { "items": { "i1": {"count": 2, "last_at": 1796000000} }, "complete_at": 0 },
"notified": { "expiring": 0, "reminder_3d": 0, "reminder_1d": 0, "overdue": 0, "complete": 0, "request_sent": 0 }
```
`request_state` is mutated inside the existing reservation lock in `receiveUpload()` (the same lock as `received`), so concurrent uploads cannot lose counts. The success path updates it. A failed upload rolls back the same way `received` does.

## 2. Upload tagging (`POST /api/fm/intake/upload`)

- New optional form field `item` (an item id). On a portal with a `request`, this rule applies:
  - Required items exist and `item` is missing or unknown: 400 `intake_item_required`.
  - Every item is optional: a missing `item` is accepted, and the file lands in the destination root (no subfolder) and counts toward nothing.
- The file extension must pass the item's `ext` (`ext_not_allowed`, same error as today, with `error_params.ext`).
- With `subfolders` on, the file goes to `<dest>/<folder>/`: `$portalFm->upload($disk, $folder, $file)`. That is still inside the portal token's `prefix`, so no scope change is needed.
- `max_files` / `max_mb` / virus / DLP / EXIF all behave exactly as today.

## 3. Completion

- **Complete:** every `required` item has `count ≥ 1`. The check runs in the same lock, at the upload that completes the request. That upload sets `request_state.complete_at = now` (once, never cleared).
- **The `intake_complete` notice** goes to the owner (`notify.to`), through the existing post-response flush path:
  - The digest entry is marked `complete: true`.
  - The flush that carries it sends one mail with subject "… — all requested documents received". The digest list of files is included, so the owner gets one mail, not two.
  - The flush is immediate (like `intake_full`), not batched for the window.
- **A requestee is set:** they get a short "Thanks — we have everything" mail, through the receipt template path and caps (§4). It never contains file names.

## 4. Requestee (the client) mail

- **Who may be a requestee.** Always the operator's choice, made in an authenticated create call; a stranger never supplies it.
  - Allowed only when one of these holds:
    - (a) the address passes `notify_to` / `notify_recipient_domains` (the same rule as owner recipients); or
    - (b) the minting token carries the new claim `intake_request_any_recipient: true` (default false, documented in CONFIG.md §2.13).
  - Otherwise: 403 `notify_recipient_denied`, the same code as step 1.
  - Address literals and invalid addresses are refused, as in step 1.
- **Storage.** The requestee address and the **portal URL** are sealed into the existing `notify` block (`requestee`, `url`). The URL contains the portal JWT, and the reminder mails need it. The sealed block is AES-GCM, bound to the record (`notifyAad`). Neither is ever returned by `intake/list` or `info`.
  - A portal with a requestee but no `intake_base_url` (so no URL) is refused: 400 `intake_request_no_url`. A reminder without a link is useless.
- **Mails to the requestee.** All are plain text, use the record's locale, and set Reply-To to `notify_reply_to`. Each carries the operator's From name.
  - `intake_request`: the link, the checklist, the due date and the operator's message. Sent at create when `send_now`; flag `request_sent`.
  - `intake_reminder`: at `due_at − 3d` and `due_at − 1d` (flags `reminder_3d`, `reminder_1d`). It lists only the **missing required** items, plus the link. A reminder whose time already passed when the sweep first sees it is skipped, never sent late; the only exception is `reminder_1d` while still before `due_at`.
  - `intake_complete` (requestee copy): "we have everything".
- **Owner mails** (to `notify.to`, if any):
  - `intake_overdue`: sent once when `due_at` has passed and the request is incomplete. Lists the missing items. Flag `overdue`.
  - `intake_complete`: see §3.
- **No mail at all:** revoked, expired, muted, or complete (except the complete mails themselves). A requestee is never mailed after `complete_at`.
- **Caps** (reusing NotifySupport `allow()` / `underDailyCap()`):
  - requestee mails: at most 1 per portal per 6 h;
  - requestee mails per address per day: `FLUXFILES_NOTIFY_RECEIPT_ADDRESS_DAILY` (default 3);
  - total requestee mails per portal: at most 6 (request + 2 reminders + complete leaves room).
- **Delivery semantics.** At-most-once, exactly as step 1: flag claimed under the store lock, then sent. A provider outage loses a reminder and never double-sends.
- **Audit.** Every send writes `notify_sent` with `detail` = `event=… n=…`. An address never appears in the audit log.

Claims: `NOTIFY_EVENTS` gains `intake_request`, `intake_reminder`, `intake_overdue`, `intake_complete`, and `notify_events` filters them like the others. `IntakeNotify::$NOTIFY_EVENTS` gains the same four. New claim `intake_request_any_recipient` (bool), with a named option in all 5 token builders (token-builder parity guard).

## 5. Sweep (`POST /api/fm/notify/run`, unchanged route)

`IntakeNotify::runNotify()` adds a pass over portals that carry a `request` and a sealed notify block:
- `reminder_3d`: when `now ≥ due_at − 3d` and `now < due_at − 1d`.
- `reminder_1d`: when `now ≥ due_at − 1d` and `now < due_at`.
- `overdue`: when `now ≥ due_at` (owner).

Each runs only while incomplete, unrevoked, unexpired and unmuted. `dry_run` reports `{jti, event, result: 'would_send'}` per item, as today. `max_emails` bounds the pass. The sweep is idempotent through the flags.

## 6. Public info (`GET /api/fm/intake/info`)

Adds, only when the portal has a `request`:
```jsonc
"request": { "items": [{"id":"i1","label":"…","required":true,"ext":["pdf"],"count":1}], "due_at": 1797000000, "message": "…", "complete": false }
```
Counts are fine to show: the holder of the link is the uploader. The requestee address, the URL and the folders are not exposed.

## 7. Operator list (`GET /api/fm/intake/list`)

Each row adds `request: {items_total, items_required, items_done_required, due_at, complete_at, overdue: bool, has_requestee: bool}`, or `null`.

## 8. UI (core `assets/fm.js` + `public/index.html` + lang ×16)

**Create modal (both kinds),** inside the existing gates:
- **E-mail me:**
  - Shown only when the token has `notify_to` or `notify_recipient_domains`.
  - Default **on** when `notify_to` is non-empty, which matches the server default.
  - Off sends `notify: {enabled: false}`.
  - Recipients: a checkbox per `notify_to` address, all checked by default. With `notify_recipient_domains`, a free-text "add address" field. Sends `notify: {to: [...]}` only when the selection differs from "all".
- **Request documents (intake only),** a disclosure section:
  - item rows (label, required checkbox), with add and remove;
  - a due date (`<input type="date">`, converted to end of that local day);
  - the client e-mail, plus "E-mail the link to my client now" (default on when an address is entered);
  - a message textarea.
  - Client validation mirrors §1. The server is authoritative.
- **The done screen** also shows "Request sent to <masked address>" when `send_now` succeeded. The server returns `request: {sent: bool}` for this.

**Links panel:**
- **Bell badge:** shown when `has_notify`. It is muted-struck when `notify_muted`. Clicking toggles `POST /api/fm/notify/mute {module, disk, jti, muted}` and refreshes the row.
- **Intake progress column:** "2/3 documents" and the due date. An "overdue" badge appears when `overdue`; a "complete" badge appears when `complete_at`.
- **"Send test e-mail" button** in the panel header: shown when the token has `notify_to`. It calls `POST /api/fm/notify/test {}`, then shows `sent` / `transport` / `error` in a toast.

**i18n:** every new UI string goes under `notify.ui.*` and `intake.request.*`, and all mail templates under `notify.intake_request_*` / `intake_reminder_*` / `intake_overdue_*` / `intake_complete_*`. Present in all 16 `lang/*.json` (the test-i18n guard). They are real translations, not English copies.

## 9. Portal page (`packages/intake/public/intake.html`)

- When `info.request` is present, the page shows:
  - the operator's `message`;
  - the due date, in the visitor's locale;
  - a progress line;
  - one row per item: label, required marker, accepted types, current count with a ✓, and its own file picker or drop target.
- A file chosen on a row is sent with `item=<id>`. The generic picker stays only when there are no required items.
- After each settled upload the page refreshes `info` (counts) and shows the complete state when done. The existing batch, `flush=1` keepalive and receipt behaviour stay.
- Page strings stay English, as the page is today (the step-1 §11 Q9 gap). Item labels and the message are operator text, rendered with `textContent`, never `innerHTML`.

## 10. WordPress Pro: "E-mail notices" section (ProAdmin)

- **Options** (per site; on multisite the network value is the default):
  - `fluxfiles_pro_notify_enabled` (bool, default on once Pro is licensed);
  - `fluxfiles_pro_notify_to` (comma list, default the site admin e-mail);
  - `fluxfiles_pro_notify_locale` (default: the site locale mapped to a FluxFiles locale);
  - `fluxfiles_pro_notify_from_name` (default: blog name);
  - `fluxfiles_pro_notify_any_client` (bool, default **on** for WordPress. The site admin is the operator. Maps to `intake_request_any_recipient`).
- **Token overrides.** When enabled, ProFeatures adds `notify_to`, `notify_locale`, `notify_from_name`, `notify_reply_to` (the admin e-mail), `notify_app_url` (the FluxFiles admin page) and `intake_request_any_recipient` to the token overrides of users allowed to use Share/Intake. Explicit `fluxfiles_token_overrides` still wins.
- **Status line.** Comes from `FluxFilesApi::mailer($why)`. It reads either "Sending through wp_mail (your SMTP plugin)" or "Sending through <provider> (FLUXFILES_MAIL_TRANSPORT)", or else shows the `$why` reason.
- **"Send test e-mail" button.** An admin-post action with a nonce and the `manage_options` capability. It sends through the same transport to the first recipient and reports the result in an admin notice. It never echoes an exception; it uses the scrubbed message.
- **Sweep.** An hourly WP-cron event, `fluxfiles_pro_notify_sweep`, scheduled on activation and cleared on deactivation. Each run:
  - mints a server-side operator claim set for each site (local disk, the site's storage prefix, the notify claims above);
  - calls `\FluxFiles\NotifyEndpoint::run()` with the plugin mailer and audit callback, `max_emails` 50.
  - Guard: only when the core has `NotifyEndpoint`, and the modules' `NotifyProviderInterface`.
  - The README notes `DISABLE_WP_CRON` + a real cron for reliable timing.

## 11. Schedulers for Laravel and standalone

- The sweep reads **one tenant store per prefix** (`<prefix>/_fluxfiles/{intakes,shares}.json`) and mails only the recipients sealed in each record. Its claims carry just the module gates, perms, disk and prefix; no `notify_*` setting can change who is mailed, so there are no scheduler env vars. `NotifyScheduler::sweep()` loops the stores and shares one `max` across the run.
- **Which stores a run covers:** every `--prefix` (repeatable), every line of `--prefixes-file`, and with `--discover` every store found under a **local** disk root (`*/_fluxfiles/intakes.json` or `shares.json`, depth <= 6, at most 10 000 stores, `_fluxfiles/trash` and symlinks skipped). With none of these, only the empty prefix. Discovery on S3/SFTP is refused: list the prefixes instead. The report says how many stores were swept; a store whose storage fails is listed and the others still run.
- **Laravel:** `php artisan fluxfiles:notify-run {--disk=local} {--prefix=*} {--prefixes-file=} {--discover} {--dry-run} {--max=50}`, with the controller's `mailer()` logic extracted into a shared resolver class. Documented with `Schedule::command('fluxfiles:notify-run --discover')->hourly()`.
- **Standalone:** `bin/fluxfiles notify:run` with the same options, using `MailTransportFactory::fromEnv()`. Documented as an hourly system cron. Exit codes: 0 ok, 1 usage/config/gate, 2 a storage or unexpected failure.

## 12. Security notes

- Requestee mail is the one place an operator can e-mail an arbitrary outside address. It is gated by a default-off claim (on WordPress, the site admin opts in through Pro), capped per address and per portal, and never triggered by a stranger: only the operator's create and the scheduled sweep send it.
- The portal URL is sealed. A tenant who can rewrite their own `intakes.json` (BYOB) cannot redirect reminders: the AAD binds the block to the jti, store and owner, and a tampered block opens to `null`, meaning no send.
- Labels and messages are operator text. Both the page and the mail render them as text, and plain-text mail is injection-free (MailMessage refuses CR/LF in headers).
- `item` is validated against the record's ids. The subfolder name is server-derived and never comes from the request.

## 13. Tests (each part ships its own)

- **Intake unit** (`tests/test-intake-requests.php`):
  - create validation (all §1 rules);
  - upload tagging, folders and the ext per item;
  - concurrent uploads keep exact counts (spy and fork, like the atomic-store test);
  - completion fires exactly once;
  - reminders and overdue run off a clock-injected sweep, once each, with no late reminder;
  - requestee gating (403), the sealed URL, caps, and nothing after completion;
  - a tampered block means no send;
  - `info` and `list` shapes.
- **Core:** the new claim, `NOTIFY_EVENTS`, CONFIG.md, token-builder parity, lang parity, the audit map.
- **UI:** Playwright on standalone `/public/` with the real modules: create with notify off/on and selected recipients, the bell mute toggle, the test-mail toast, create with a request, the progress column. Portal page: per-item upload, progress, complete.
- **Live:**
  - Mailpit end to end: the request mail with a working link → item uploads → the complete mails; a clock-shifted sweep for reminders and overdue;
  - WP + Pro (wp-env): the settings section, the test button, the cron sweep;
  - Laravel: the artisan command;
  - all three modules-live rigs.
