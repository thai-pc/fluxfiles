# FluxFiles — Industry Presets

Ready-made `fluxfiles_token()` configs for common client verticals. Every FluxFiles
token *is* the config — the server is stateless, so "configuring FluxFiles for a
photography studio" just means minting a token with the right claims. These presets
turn that into a copy-paste starting point instead of reading all the claims in
[`CONFIG.md`](CONFIG.md) from scratch.

Each preset lists: who it's for, what it turns on, and whether it needs a paid
module (see [fluxfiles.io/pricing](https://fluxfiles.io/pricing) — the free core
covers everything that isn't marked **Pro**/**Studio**/**Enterprise** below). All
snippets use the one-options-array form of `fluxfiles_token()`; every claim not
named as its own option goes through the `claims` escape hatch (see §1 of
`CONFIG.md`). Treat these as starting points — every claim is still overridable per
tenant/request.

---

## 1. WordPress / web agency — client media libraries

**For:** agencies running many client WordPress (or static) sites who want a
faster, storage-agnostic replacement for the default media library, scoped one
prefix per client. **Free core** — no paid module required.

```php
$token = fluxfiles_token([
    'user'        => "client-{$clientId}",
    'perms'       => ['read', 'write', 'delete'],
    'disks'       => ['s3'],                    // or 'local' for small/budget sites
    'prefix'      => "sites/{$clientId}/",       // hard isolation between clients
    'maxUploadMb' => 25,
    'allowedExt'  => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'docx'],
    'ttl'         => 3600,
    'claims'      => [
        'auto_optimize' => true,                 // WebP at rest, no per-image tooling
    ],
]);
```

Pairs with the WordPress adapter's attachment bridge (`packages/wordpress`) so
picked files register as real WP attachments — see its `readme.txt` for the setup.

---

## 2. Photography / creative studio — client proofing & delivery

**For:** studios delivering finished galleries to clients without giving them an
account. **Pro** (`allow_share`) — branded, expiring, watermarked delivery links.

```php
$token = fluxfiles_token([
    'user'   => "studio-{$photographerId}",
    'perms'  => ['read', 'write'],
    'disks'  => ['s3'],
    'prefix' => "galleries/{$jobId}/",
    'edition'=> 'pro',                           // -> allow_share + allow_intake + allow_optimize
    'claims' => [
        'share_brand_name'   => 'Acme Photography',
        'share_brand_color'  => '#111111',
        'watermark_enabled'  => true,               // preview-only browsing token; also forces allow_download off
    ],
]);
```

The gallery link's own expiry (e.g. "valid 14 days") is set **per share**, in the
`ttl` field of the `POST /api/fm/share {disk, path, ttl, label?, ...}` request body
at creation time — it is not a JWT claim, so it doesn't belong in the token above.
`share_url_ttl` is a different, narrower claim: how long the *presigned S3/R2
download URL* a share redirects to stays fetchable once requested (clamped to
10–300 seconds in `CONFIG.md`) — it does not control how long the share link itself
remains valid, and setting it to something like "14 days" would simply be clamped
down to 300 seconds. Don't confuse the two.

Mint a *separate*, non-watermarked token (no `watermark_enabled`) when the client has
paid and should get the clean-file share link — `POST /api/fm/share` bakes its own
scoped, preview-only-by-default token per share regardless, so the watermark claim
here only affects how *staff* browse the gallery before sharing it out.

---

## 3. Accounting / legal / professional services — client document intake

**For:** firms that need clients to send documents in without a portal login.
**Pro** (`allow_intake`); add **Enterprise** (`allow_virus_scan`) for regulated
clients who can't accept unscanned uploads.

```php
$token = fluxfiles_token([
    'user'      => "firm-{$staffId}",
    'perms'     => ['read', 'write'],
    'disks'     => ['s3'],
    'prefix'    => "clients/{$clientId}/intake/",
    'ownerOnly' => true,                          // staff can't touch each other's client folders
    'edition'   => 'enterprise',                  // -> allow_optimize + allow_share + allow_intake + allow_virus_scan + allow_c2pa
    'claims'    => [
        'intake_brand_name'  => 'Acme & Co.',
        'intake_brand_color' => '#0a3d62',
        'allowed_ext'        => ['pdf', 'jpg', 'png', 'docx', 'xlsx'],
    ],
]);
```

Drop `'edition' => 'enterprise'` down to `'pro'` (no virus scan) for firms that
don't need the compliance module.

---

## 4. SaaS operator — per-tenant file storage inside your own product

**For:** developers embedding FluxFiles so *their* users get file management,
tiered by the SaaS operator's own pricing. **Studio** (`allow_webhooks`,
`allow_versioning`) so the operator's backend can react to tenant file events.

```php
$token = fluxfiles_token([
    'user'         => $tenantUserId,
    'perms'        => ['read', 'write', 'delete'],
    'disks'        => ['s3'],
    'prefix'       => "tenants/{$tenantId}/",
    'maxStorageMb' => $tenantPlan->storageMb,      // enforce the tenant's own plan quota
    'maxFiles'     => $tenantPlan->maxFiles,
    'claims'       => [
        'allow_webhooks'   => true,
        'webhook_url'      => 'https://api.acme-saas.com/hooks/fluxfiles',
        'webhook_events'   => ['upload', 'delete'],  // narrow to what your backend acts on
        'allow_versioning' => true,
        'versioning_max'   => 5,
    ],
]);
```

Note: there is no `'edition' => 'studio'` preset in code today (only `pro`, `agency`,
`enterprise` are defined in `embed.php`) — this example sets the Studio-tier claims
explicitly via the `claims` map for that reason, rather than relying on an
`edition` shortcut. See `LICENSING-PLAN.md`'s "code gap, not yet fixed" note.

---

## 5. Hosting panel / VPS management agency

**For:** agencies offering a cPanel-style file manager over their clients' VPS
boxes. **Free core** — SFTP is a built-in disk driver, terminal/chmod/editor are
free config toggles.

```php
$token = fluxfiles_token([
    'user'   => "vps-{$serverId}",
    'perms'  => ['read', 'write', 'delete'],
    'disks'  => ['sftp'],
    'prefix' => '/home/client/',
    'ttl'    => 1800,                             // short TTL — re-mint per admin session
    'claims' => [
        'allow_terminal'   => true,
        'terminal_pty_url' => 'https://term.acme.com/', // BYO ttyd/gotty for a real interactive shell
        'allow_chmod'      => true,
        'allow_code_edit'  => true,                // .env / nginx.conf / wp-config.php
    ],
]);
```

---

## 6. Regulated / compliance-heavy (finance, healthcare, government)

**For:** self-hosted, air-gappable deployments where data can't leave the
customer's own infra. **Enterprise** — virus scan is fail-closed, C2PA signs
provenance, everything is owner-scoped and audited.

```php
$token = fluxfiles_token([
    'user'      => $regulatedUserId,
    'perms'     => ['read', 'write'],
    'disks'     => ['s3'],                        // typically the customer's own bucket (BYOB)
    'prefix'    => "records/{$caseId}/",
    'ownerOnly' => true,
    'edition'   => 'enterprise',                  // -> allow_optimize + allow_share + allow_intake + allow_virus_scan + allow_c2pa
    'claims'    => [
        'allowed_ext' => ['pdf', 'tiff', 'docx'],
        'dedupe_uploads' => true,
    ],
]);
```

Combine with `fluxfiles_byob_token()` instead of `fluxfiles_token()` when the
customer must use their own S3/R2 bucket rather than the operator's — see
`CONFIG.md` §2.12 "BYOB (Bring Your Own Bucket)" and `embed.php`.

---

## Notes

- **`edition` is DX sugar, not the license gate.** It just pre-fills the claims a
  tier usually wants (`fluxfiles_apply_edition_preset` in `embed.php`) — the real
  enforcement is still the operator's license key + the module being installed. A
  claim from an unlicensed/uninstalled module is simply ignored server-side. Today
  that preset only exists for `pro` / `agency` / `enterprise` — there is no `studio`
  preset, so Studio-tier tokens (preset #4 above) set their claims explicitly.
- Every claim above is documented in full (defaults, clamping, sanitization) in
  [`CONFIG.md`](CONFIG.md) — these presets only combine existing claims, they don't
  introduce new ones.
- These are starting points, not fixed packages — mix and match freely (e.g. add
  `allow_webhooks` to the photography preset if the studio wants a Zapier
  notification on every gallery upload).
