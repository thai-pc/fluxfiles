---
paths:
  - "packages/core/embed.php"
  - "packages/node/src/token.ts"
  - "packages/laravel/src/FluxFilesManager.php"
  - "packages/wordpress/includes/FluxFilesPlugin.php"
  - "packages/python/**"
  - "docs/design/ACL-ROLE-PRESETS-DESIGN.md"
---

**ACL role presets** (`docs/design/ACL-ROLE-PRESETS-DESIGN.md`): a mint-time-only `role` claim-bundle preset — **four roles**, `viewer`/`editor`/`admin`/`superadmin` (`superadmin` shares `admin`'s claim bundle but is the one role documented as safe to combine with an empty `prefix`), structurally parallel to the existing `edition` preset — shipped across all the token builders (`packages/core/embed.php`'s `fluxfiles_role_preset()`/`fluxfiles_apply_role_preset()`, `packages/node/src/token.ts`'s `ROLE_PRESETS`, Laravel's `FluxFilesManager.php`, WordPress's `FluxFilesPlugin.php`, and later the Python SDK) in commit `70f1cea` (2026-09-01). Same-day fix (`d880b98` core/Laravel/WordPress, `fb7c8a2` Node) for a `viewer`/`editor` bug: the presets originally omitted `allow_extract`/`allow_chmod`, assuming an absent claim decodes false, but `Claims::fromJwtPayload` defaults those two specifically to **true** when absent — so a minted `viewer`/`editor` token unintentionally carried `allow_chmod=true`, letting a read-only/contributor role chmod on an SFTP disk. Fixed by setting both explicitly in every builder. No new server-side state and no new JWT claim: `role` only expands to *existing* claims at mint time (never touches `prefix`/`disks`/`userId`/quota/rate-limit/BYO-embed-URL claims, and never implies any paid-module `allow_<x>` claim or `allow_terminal` — terminal access stays an explicit opt-in regardless of role). WordPress maps WP capabilities to roles; `admin` needs `manage_options` or `delete_others_posts` (since wordpress-v0.2.54 — `delete_posts` alone used to mint admin for Contributors/Authors).
