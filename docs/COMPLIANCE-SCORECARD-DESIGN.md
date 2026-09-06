# Compliance Readiness Scorecard — Design Spec

> **Status: proposed, not implemented.** This spec grounds a new read-only
> dashboard screen in the existing `/api/fm/usage` + `/api/fm/license`
> pattern. It designs against `allow_dlp_scan` (module id `dlp`, capability
> "DLP / PII redaction") and `allow_legal_hold` (module id `legal-hold`,
> capability "Legal Hold") as **assumed, not-yet-final** names for two
> modules other agents are spec'ing in parallel. Neither exists in
> `Claims.php` or `ModuleRegistry::$map` today — see §9 for the exact
> follow-up this spec needs once those land.

## 0. Scoping correction (read first)

The task frames this as something that "sells as part of the Enterprise
compliance bundle." Taken literally that would mean gating the scorecard
itself behind `allow_audit_export`/`allow_sso`/a license check. **This spec
deliberately does not do that** — see §2 for the full reasoning — because a
funnel/upsell surface that only paying customers can see isn't a funnel. The
scorecard ships **free/core**, unconditionally, like `/api/fm/license` and
`/api/fm/usage` already do. It *markets* the Enterprise bundle (by showing
every operator, including free-tier ones, exactly what they're missing); it
is not *sold as* the Enterprise bundle. This is consistent with the
project's stated philosophy that free/visible surface area is the funnel,
not lost revenue (see `pro_hints` claim, `proGate()`'s `'locked'` state).

## 1. Problem & who pays

**Persona:** the Enterprise/Compliance buyer (a CISO, IT security lead, or
compliance officer evaluating FluxFiles for a regulated deployment —
healthcare, finance, legal, gov contractor). This persona doesn't read
`docs/CONFIG.md`; they want a dashboard screen they can screenshot for an
internal review or show a prospect's security questionnaire reviewer.

**Free or paid?** The scorecard **itself is free/core** — no new claim, no
module, no license check to view it (§2). What it reports on is a mix of
paid (virus, c2pa, audit-export, sso, and the two forthcoming modules) and
free capabilities. It is a sales/demo tool in the sense that it is the
in-product surface that makes the Enterprise bundle's value legible and
nudges an unlicensed operator toward buying it — not a feature that itself
needs a license to exist.

**Build cost:** near-zero. No new engine, no new storage, no new
enforcement path. It is a formatting/aggregation layer over
`Claims::isAllowed()`, `ModuleRegistry::installed()`, and
`LicenseManager::licensed()`/`info()` — three things that already exist and
are already computed on every paid-module request.

## 2. Architecture fit

Fully stateless, same as `/api/fm/usage` and `/api/fm/license`:

- **No new storage.** No `_fluxfiles/*.json`, no sidecar, no cache file. Every
  field in the response is computed fresh from (a) the already-decoded
  `Claims` object for this request, (b) `LicenseManager::fromEnv()` (reads
  `FLUXFILES_LICENSE_KEY`, same as `/api/fm/license` already does), (c)
  `ModuleRegistry::installed($id)` (a `class_exists` check — no I/O), and (d)
  one env var read for the SSO row (`FLUXFILES_SSO_ENABLED`, see below).
- **No new JWT claims required to compute it** (§5). The checklist reads
  claims that already exist (`allow_virus_scan`, `allow_c2pa`,
  `allow_audit_export`) plus one env var (SSO) plus two forthcoming claims
  this spec assumes the names of.
- **Gating: the `audit` permission, not a new claim.** `audit` is already
  the established "this token may see admin/introspection-level information"
  gate in this codebase — it's what `/api/fm/audit`, `/api/fm/audit/export`,
  and `/api/fm/audit/purge` all check (`$claims->hasPerm('audit')`,
  `packages/core/api/index.php:1089/1110/1129`). The scorecard is
  structurally the same kind of thing (introspection over the tenant's own
  configuration/activity), so it reuses the same perm rather than inventing
  `allow_compliance_scorecard`. A token minted without `audit` gets `403
  forbidden`, exactly like trying to read the activity log.
- **Does an "available but off" answer leak anything?** No — worked through
  explicitly, as asked:
  - **Module availability (installed + licensed) is not a secret.** It's
    already returned in full by the pre-existing, unauthenticated-adjacent
    `GET /api/fm/license` (`{edition, modules: [...]}`), which any holder of
    *any* valid JWT — not just an `audit`-permed one — can already call. The
    scorecard doesn't expose a new fact by repeating "c2pa: available" next
    to a checkbox.
  - **Per-token claim state is not a secret either, and this is the
    stronger argument.** JWTs in this system are **signed, not encrypted**
    (`firebase/php-jwt` HS256) — a caller holding their own `Authorization:
    Bearer` token can already base64-decode its payload and read
    `allow_virus_scan`/`allow_c2pa`/`allow_audit_export` directly, with no
    endpoint involved at all. The scorecard's `enabled` field tells an
    `audit`-permed caller nothing about their own token they couldn't
    already read themselves; its only value-add is turning that into a
    formatted, human-readable checklist with a "why not" and a copy-paste
    snippet, and merging in the two facts (installed/licensed) that aren't
    inside the token.
  - **What genuinely must stay hidden is unaffected**: actual DLP scan
    findings, actual legal-hold case contents, actual audit log rows, actual
    virus-scan verdicts. None of those are returned here — see §3's explicit
    "does NOT return" list.
  - **Conclusion:** viewing the scorecard needs only the existing `audit`
    perm; no new claim, no license/module check to gate the *view* itself.

## 3. The "not a legal compliance claim" framing constraint (read before building the UI)

This is a hard constraint, not a style preference: **FluxFiles has not been
audited for GDPR/SOC2/HIPAA/any regulatory framework, and nothing in this
feature may imply that it has.** A customer who screenshots a
"92% compliant" badge and hands it to their own auditor, or pastes it into a
security questionnaire response, creates real liability for this project —
we would be the source of a false compliance representation we never made
and cannot back up with an audit.

Concretely, this means:

- The API and UI describe **FluxFiles feature toggles**, never "compliance
  status." Every string a human reads must be feature-framed:
  - Do: *"4 of 6 available compliance-relevant features enabled."*
  - Don't: *"67% compliant."* / *"GDPR ready."* / *"SOC 2 compliant."* /
    *"Compliance score: 67%."*
- No field, response key, i18n string, CSS class, or UI label may contain
  the words "compliant"/"compliance score"/"certified"/"audit-passed" in a
  way that reads as a certification. The word "compliance" is fine only in
  the sense of "these are compliance-*relevant* features" (i.e. features a
  compliance program commonly wants), never "you are compliant."
- The response carries a fraction (`enabled_count`/`available_count`/
  `total_count`), not a field named anything like `score` or `percent`. If
  the UI renders a percentage, it computes it client-side from the fraction
  and must render it adjacent to, never in place of, the disclaimer string
  the API returns (`disclaimer` field, §4) — e.g. "4/6 features enabled —
  this reflects FluxFiles configuration only, not a legal or regulatory
  compliance certification."
- No PDF/exportable "certificate" artifact in v1. If a future request wants
  a downloadable report, it needs its own explicit legal review before
  being added — out of scope here, flagged again in §9.

## 4. Endpoint

### `GET /api/fm/compliance/scorecard`

- **Auth:** main access JWT (`Authorization: Bearer`), like every other
  authenticated route. **Not** a public/pre-auth route — no new token type.
- **Perm required:** `audit` (see §2). `403 forbidden` without it — same
  error code and shape as `/api/fm/audit`.
- **No module/license gate on the route itself** (§2). It always returns
  200 for any `audit`-permed token, including on unlicensed free core (every
  paid row simply reads `available: false`).
- **No query parameters in v1** (no `disk`, no filtering — this is a
  per-token capability summary, not a per-disk or per-file report; none of
  the six capabilities are disk-scoped in a way that would make a `disk`
  param meaningful).
- **Response shape:**

```jsonc
{
  "data": {
    "generated_at": 1798675200,           // unix seconds, computed fresh every call
    "disclaimer": "Reflects which FluxFiles features are enabled for this token. Not a legal or regulatory compliance certification.",
    "summary": {
      "enabled_count": 2,
      "available_count": 4,               // installed + licensed, regardless of claim
      "total_count": 6
    },
    "categories": ["content_security", "content_provenance", "audit_retention", "identity_access", "data_protection", "legal_ediscovery"],
    "items": [
      {
        "id": "virus_scan",
        "label": "Virus / malware scanning on upload",
        "category": "content_security",
        "module": "virus",
        "claim": "allow_virus_scan",
        "enabled": true,
        "available": true,
        "status": "on",                    // "on" | "off" | "locked" — see §6
        "why_not": null,
        "claim_snippet": null,
        "docs_url": null
      },
      {
        "id": "c2pa",
        "label": "Content provenance (C2PA)",
        "category": "content_provenance",
        "module": "c2pa",
        "claim": "allow_c2pa",
        "enabled": false,
        "available": true,
        "status": "off",
        "why_not": "claim_off",
        "claim_snippet": "'claims' => ['allow_c2pa' => true]",
        "docs_url": null
      },
      {
        "id": "audit_export",
        "label": "Audit log export & retention purge",
        "category": "audit_retention",
        "module": "audit-export",
        "claim": "allow_audit_export",
        "enabled": false,
        "available": false,
        "status": "locked",
        "why_not": "not_licensed",
        "claim_snippet": null,
        "docs_url": "https://fluxfiles.dev/pricing"
      },
      {
        "id": "sso",
        "label": "SSO login bridge (OIDC)",
        "category": "identity_access",
        "module": "sso",
        "claim": null,
        "enabled": false,
        "available": false,
        "status": "locked",
        "why_not": "not_installed",
        "claim_snippet": null,
        "docs_url": "https://fluxfiles.dev/pricing"
      },
      {
        "id": "dlp_scan",
        "label": "DLP / PII redaction",
        "category": "data_protection",
        "module": "dlp",
        "claim": "allow_dlp_scan",
        "enabled": false,
        "available": false,
        "status": "locked",
        "why_not": "not_installed",
        "claim_snippet": null,
        "docs_url": "https://fluxfiles.dev/pricing"
      },
      {
        "id": "legal_hold",
        "label": "Legal hold",
        "category": "legal_ediscovery",
        "module": "legal-hold",
        "claim": "allow_legal_hold",
        "enabled": false,
        "available": false,
        "status": "locked",
        "why_not": "not_installed",
        "claim_snippet": null,
        "docs_url": "https://fluxfiles.dev/pricing"
      }
    ]
  },
  "error": null
}
```

- **`why_not` enum:** `null` (enabled) | `claim_off` (installed + licensed,
  but this token's claim/env is off) | `not_licensed` (installed, no
  license) | `not_installed` (module package absent — always true on free
  MIT core for all six rows).
- **`enabled` computation per row** — this is the one place the design
  departs from a uniform "read one claim" rule, and it must be documented
  per-row because SSO is gated differently from the rest (see
  `.claude/CLAUDE.md`'s SSO note — pre-auth, no per-token claim):
  | id | `enabled` source |
  |---|---|
  | `virus_scan` | `$claims->allowVirusScan` (`Claims::isAllowed('allow_virus_scan')`) |
  | `c2pa` | `$claims->allowC2pa` |
  | `audit_export` | `$claims->allowAuditExport` |
  | `sso` | `($_ENV['FLUXFILES_SSO_ENABLED'] ?? 'false') === 'true'` — **not** a Claims field; SSO's layer-3 gate is a server env flag, not a per-token claim (`SsoModule::claim()` returns `''`) |
  | `dlp_scan` | `$claims->isAllowed('allow_dlp_scan')` — assumed name, §9 |
  | `legal_hold` | `$claims->isAllowed('allow_legal_hold')` — assumed name, §9 |
- **`available` computation per row (all six, uniform):**
  `ModuleRegistry::installed($module) && LicenseManager::fromEnv()->licensed($module)`.
- **Explicitly does NOT return:** actual audit log rows, actual virus-scan
  verdicts, actual DLP findings, actual legal-hold case data, any field
  named `score`/`percent`/`compliant`, any per-file or per-path data. This
  is a capability checklist, not a report on file contents.
- **Errors:** `403 forbidden` (missing `audit` perm) — the only error case;
  everything else always 200 (unknown/forthcoming module ids just read as
  `available: false`).

### Implementation shape (core)

A new stateless class, `packages/core/api/ComplianceScorecard.php`, holding
the static capability table (id/label/category/module/claim) and a
`build(Claims $claims, LicenseManager $license): array` method — the exact
same "shared class both core `index.php` and the Laravel/WP proxies call"
pattern as `QuotaManager`/`OptimizeStats`, so proxy parity (§7) is a ~10-line
controller method, not a reimplementation.

```php
if ($method === 'GET' && $uri === '/api/fm/compliance/scorecard') {
    if (!$claims->hasPerm('audit')) {
        throw new ApiException('Permission denied', 403, 'forbidden');
    }
    return \FluxFiles\ComplianceScorecard::build($claims, \FluxFiles\LicenseManager::fromEnv());
}
```

## 5. JWT claims

**No new claims required for v1.** This is a derived view over claims that
already exist (`allow_virus_scan`, `allow_c2pa`, `allow_audit_export`) plus
an env var (SSO) plus the two forthcoming modules' own claims (owned by
their specs, not this one). Explicitly confirming the reasoning asked for:

- It is not a new capability an operator opts into — it is a read of
  capabilities that already have their own opt-in claims. Adding a wrapping
  claim (e.g. `allow_compliance_scorecard`) would gate visibility of a
  free/core dashboard behind a claim for no enforcement reason, which is
  inconsistent with `/api/fm/usage`/`/api/fm/license` having no such gate.
- The one candidate for a *new* claim — a way to suppress the scorecard for
  operators who don't want their tenants seeing an upsell surface at all —
  already exists: `pro_hints` (`false` = never show Pro affordances). The
  scorecard's `locked` rows should respect `pro_hints` the same way
  `proGate()` does (§6), so no new claim is needed there either.

**Nothing to add to `docs/CONFIG.md` for this spec.** (When the DLP/legal-hold
specs land their own `allow_dlp_scan`/`allow_legal_hold` claims, those are
documented by *those* specs, not duplicated here.)

## 6. UI

This feature *is* the UI: a new dashboard screen (`showComplianceScorecard`
Alpine state, mirroring `showUsage`), reachable from the same admin surface
as the Usage dashboard (e.g. a "Compliance" tab/button next to "Usage",
gated only by `canAudit` — the existing `hasPerm('audit')` check already
used to show the Activity Log entry point).

**Per-row visual state — three states, but a different three from
`proGate()`'s, and deliberately so:**

`proGate()`'s `on`/`hidden`/`locked` exists to avoid *advertising* a
feature the operator deliberately withheld from a tenant (Share/Intake
*are* the product surface a normal user sees). The scorecard is the
opposite kind of screen — an **admin-only configuration audit** whose whole
purpose is to surface what's off. Hiding an "available but off" row here
would defeat the feature. So the three states are:

| state | condition | rendering |
|---|---|---|
| `on` | `enabled === true` | green check, label, no action |
| `off` | `available === true && enabled === false` | gray/amber "off" indicator + `why_not: claim_off` text + a **copy-paste claim snippet** (`claim_snippet` field, a `<code>` block with a copy button — same interaction as any existing code-snippet UI, no precedent to reuse verbatim since none exists yet, but same visual weight as the audit-export "Pro" teaser button) |
| `locked` | `available === false` | lock icon + `why_not` text (`not_installed` or `not_licensed`) + the same generic Pro-teaser CTA already used by `auditExportGate`'s `'locked'` branch (`ff-pro-locked-*` class, `openLocked()`), linking to `docs_url` |

`pro_hints` interaction: when `pro_hints` is `false` (an operator who never
wants Pro affordances shown), `locked` rows render as a plain disabled
row with the "why_not" text but **no** upsell CTA/link — same restraint
`proGate()` already applies elsewhere. `off` rows are unaffected by
`pro_hints` (they're not upsell — the operator already licensed that
capability and just hasn't turned the claim on for this token).

**Header:** `summary.enabled_count`/`available_count`/`total_count`
rendered as *"4 of 6 available features enabled"*, with `disclaimer`
rendered directly underneath in smaller/muted text, always visible,
never collapsed or hideable — this is the liability guardrail from §3
and must not be an easy-to-miss tooltip.

**No new i18n key namespace needed beyond the six item labels + the
disclaimer + `off`/`locked` reason strings** — add under a new
`compliance.*` key group in `lang/en.json` (and all 15 other locales, per
the existing ×16 requirement) rather than reusing `audit.*` or `links.*`,
since none of the existing groups fit. Roughly:
`compliance.title`, `compliance.disclaimer`, `compliance.summary`
(`{enabled}/{total} …`, plural-aware via `I18n::tp()`), `compliance.status_on`,
`compliance.status_off`, `compliance.status_locked`,
`compliance.reason_claim_off`, `compliance.reason_not_licensed`,
`compliance.reason_not_installed`, `compliance.copy_snippet`,
`compliance.upgrade_cta`, plus the six item labels
(`compliance.item.virus_scan`, `.c2pa`, `.audit_export`, `.sso`,
`.dlp_scan`, `.legal_hold`) and their category labels (6 more). ~20 keys
× 16 locales.

## 7. Laravel / WordPress proxy parity

**Yes, both should expose this**, for the same consistency reason
`/api/fm/license` and `/api/fm/usage` are already proxied — an operator
running the embedded UI through either adapter should see the same
Compliance tab a standalone-core user sees. Concretely:

- `packages/laravel/src/Http/Controllers/FluxFilesController.php` — add a
  `complianceScorecard(Request $request): JsonResponse` method, same shape
  as the existing `license()`/`usage()` methods (§4's implementation
  snippet, ported): build `$claims = $this->claims($request)`, rate-limit
  read, check `hasPerm('audit')`, call
  `\FluxFiles\ComplianceScorecard::build($claims, \FluxFiles\LicenseManager::fromEnv())`,
  wrap in `$this->ok(...)`. Route it in `packages/laravel/routes/fluxfiles.php`
  next to the existing `audit`/`audit-export`/`license` routes. Needs a
  composer floor bump to whatever core tag ships `ComplianceScorecard.php`
  (same "floor = first release the proxy *calls* it" rule as every other
  ported route — see the git-deploy/AI-vision precedent in `.claude/CLAUDE.md`).
- `packages/wordpress/includes/FluxFilesApi.php` — add a
  `handleComplianceScorecard()` REST callback under
  `/wp-json/fluxfiles/v1/compliance/scorecard`, same pattern as
  `handleUsage()`/`handleAuditExport()` already there. Same core-floor bump.
- **Risk is lower than a write-path module port**, as the task notes: this
  is read-only, has no request body to validate, no SSRF surface, no
  storage side effects — a mis-port can return a wrong/stale number but
  can't corrupt anything or open a write hole. Still worth doing for parity
  rather than leaving the adapters' admins staring at a 404.
- Both proxies already build their own `Claims`/`LicenseManager` instances
  per-request (see `FluxFilesController::license()`/`usage()` above), so
  this is a straight port of the same ~10 lines core's route handler uses —
  no new shared-state concern.

## 8. Test plan

**Unit** (`packages/core/tests/unit/test-compliance-scorecard.php`, new):
- Six rows always present, ids match the static table, in stable order.
- `available` for `virus`/`c2pa`/`audit-export` is `false` on an unlicensed
  `LicenseManager` (free core) and `true` only when both
  `ModuleRegistry::installed()` (fake/registered test class) and
  `licensed()` return true — mirror the existing `ModuleRegistry`/
  `LicenseManager` unit tests' fixture style (`ModuleRegistry::register()`/
  `reset()` for a fake test module class, already used elsewhere).
- `enabled` for `virus_scan`/`c2pa`/`audit_export` tracks the corresponding
  `Claims` boolean exactly (true/false claim → true/false `enabled`,
  independent of `available`) — i.e. a claim can be `true` on an unlicensed
  server and the row still reports `enabled: true, available: false,
  status: locked` (claim-on-but-can't-actually-run is a real, useful signal
  to show, not collapsed into "off").
- `sso` row's `enabled` reads `FLUXFILES_SSO_ENABLED` env, not any Claims
  field — test with the env var toggled, independent of any claim on the
  token.
- `dlp_scan`/`legal_hold` rows: with neither module registered (today's
  state), always `available: false, enabled: false, status: locked,
  why_not: not_installed` — this is the "degrades gracefully ahead of those
  modules landing" behavior from §9, worth locking in a test now so a
  future PR that registers the real module doesn't silently break this row.
- `summary.enabled_count`/`available_count`/`total_count` arithmetic matches
  the `items` array exactly (regression guard against a hand-maintained
  counter drifting from the row list).
- No `score`/`percent`/`compliant` key anywhere in the response — a small
  static assertion (`assertArrayNotHasKeyRecursive` style) that keeps the
  §3 guardrail from silently regressing if someone "helpfully" adds a
  percentage field later.
- `docs/CONFIG.md` sync: **no claim added by this spec**, so
  `tests/unit/test-config-doc.php` needs no update — call this out in the
  PR description so a reviewer isn't surprised `CONFIG.md` is untouched.

**Integration** (`packages/core/tests/integration/`, or extend an existing
route-level test file):
- `GET /api/fm/compliance/scorecard` without `audit` perm → `403 forbidden`.
- With `audit` perm, on a token with `allow_virus_scan: true` and
  `allow_c2pa: false`, `virus_scan.enabled === true`,
  `c2pa.enabled === false` (regardless of module install state).
- Response envelope is `{data, error: null}` like every other route (this
  one, unlike `/audit/export`, does NOT bypass the JSON envelope).

**E2E / browser** (`tests/browser`, Playwright):
- Compliance tab only visible for an `audit`-permed token; absent otherwise
  (mirrors the existing Activity Log visibility test, if one exists — reuse
  its `canAudit` fixture token).
- Each of the three row states (`on`/`off`/`locked`) renders visibly
  distinct markup — assert on the state class, not exact copy (copy will
  change across the 16 locales).
- The disclaimer string is present and visible (not just in the DOM but
  not `display:none`) whenever the summary is shown — a direct regression
  test for the §3 guardrail at the UI layer, not just the API layer.
- `pro_hints: false` token → `locked` rows render with no upgrade CTA link.

**Adapter smoke** (`packages/laravel/tests/test-*-smoke.php`,
`packages/wordpress/tests/test-*-smoke.php`): a stubbed call through each
proxy's new controller/REST method returns the same six-row shape as core,
gated the same way (`audit` perm) — same pattern as the existing
`license`/`usage` smoke coverage in each package.

## 9. Open questions / trade-offs

1. **DLP/legal-hold claim & module ids are assumed, not confirmed.** This
   spec bets on `allow_dlp_scan`/module id `dlp` and
   `allow_legal_hold`/module id `legal-hold`. If the parallel specs land
   different names, the only file this spec's implementation needs to touch
   is the static capability table in `ComplianceScorecard.php` (one line
   per renamed id) — nothing else in this design depends on the exact
   string. Flagging so whoever implements this checks the final names
   against those two specs' `docs/CONFIG.md` entries before shipping, not
   after.
2. **Should the scorecard eventually include free/hygiene settings**, not
   just the six paid-adjacent modules — e.g. `owner_only`,
   `audit_retention_days` being set to a nonzero value, SFTP
   `require_host_key`/`strict_algorithms`? These are real compliance-adjacent
   toggles but they're per-disk config (not JWT claims for the SFTP ones) or
   have no natural "available/locked" axis (they're always available, free).
   Left out of v1 to keep the checklist's on/off/locked model uniform across
   all rows; a v2 "Hygiene" section with a different two-state (on/off, no
   `locked`) model is a plausible follow-up, not designed here.
3. **Should there be a downloadable/printable artifact** (PDF export of the
   scorecard) for a buyer to attach to a vendor security review? Explicitly
   deferred — §3's liability concern gets sharper the moment the output
   leaves the live, always-current dashboard and becomes a static file
   someone can staple to a compliance filing months later after the
   underlying claims changed. If this is wanted, it needs its own legal
   sign-off on the exact wording before any implementation, not a UI nicety
   bolted onto this spec.
4. **Categorization taxonomy** (`content_security`/`content_provenance`/
   `audit_retention`/`identity_access`/`data_protection`/
   `legal_ediscovery`) is this spec's invention, one category per row today
   since there's exactly one capability per category in v1. It only earns
   its keep once a category has ≥2 rows (e.g. if a future capability also
   lands under `identity_access`) — until then it's a label with no grouping
   behavior, and it's simplest to keep it as data (not fold it away) so the
   UI doesn't need a breaking change when that happens.
5. **Multi-tenant "org-wide" view.** Today's scorecard is inherently
   per-token (it reads the caller's own `Claims`) — an operator with many
   tenants/prefixes would need to call it once per token to see the whole
   fleet's posture. No aggregation-across-tenants endpoint is proposed here;
   that would need a way to enumerate tenants, which doesn't exist anywhere
   else in this stateless architecture either (there is no tenant list to
   query). Out of scope, consistent with the "no central DB" grain.

---

## Summary of claims to add to `docs/CONFIG.md`

**None.** This spec introduces zero new JWT claims (§5). The only
`docs/CONFIG.md`-relevant follow-up is indirect: whichever specs ship
`allow_dlp_scan` and `allow_legal_hold` should cross-check their final claim
names against §9 item 1 above so this spec's capability table can be
updated to match before `ComplianceScorecard.php` is implemented.
