# FluxFiles documentation index

Documents live in four folders by type. Keep filenames stable once published —
package READMEs, the changelog and external links point at these paths — and add
every new document to the matching section below.

## Start here (guides)

- [Guide](guides/GUIDE.md) — the full reference that used to be the repository README: install, Docker, configure, embed (SDK options, events, commands, token refresh), storage disks and BYOB, file operations, admin tools, token structure, multi-tenant, adapters, i18n, security, testing, troubleshooting.
- [Configuration reference](reference/CONFIG.md) — every JWT claim and server env var (the single source of truth; CI-guarded against `Claims.php`).
- [Deployment](guides/DEPLOYMENT.md) — self-hosting, web-server configuration, upload limits.
- [API reference](guides/API.md) — standalone HTTP routes, incl. tus resumable upload and the paid-module routes.
- [Feature deep dives](guides/FEATURES.md) — on-demand WebP/AVIF, srcset, watermark, usage dashboard, the BYO terminal embed, About dialog and white-label.
- [Industry presets](guides/INDUSTRY-PRESETS.md) — copy-paste token configs per vertical.
- [llms.txt](../llms.txt) — for AI assistants integrating FluxFiles: a one-file summary (architecture, install paths, packages, token example, security rules) with links to the raw Markdown docs ([llmstxt.org](https://llmstxt.org) convention). The website also serves `llms-full.txt` with these docs inlined.
- [Migrating from an unmaintained file manager](guides/MIGRATING.md) — Responsive FileManager, KCFinder, Roxy Fileman, RichFilemanager, Tiny File Manager, File Browser: concept mapping, before/after snippets (TinyMCE, CKEditor 4/5), keeping existing upload folders and URLs, indexing existing files, FAQ.

## Operators and sellers

- [Activation](guides/ACTIVATE.md) — install and enable paid modules, licence keys, SSO setup, error meanings.

## Reference

- [Architecture](reference/ARCHITECTURE.md) — monorepo layout, integration patterns, publish topology, CI map.
- [Metadata storage](reference/METADATA-STORAGE-DESIGN.md) — how metadata, trash and audit travel with user storage (the default `json` backend), and what each runtime file under `_fluxfiles/` and `storage/` is.

## Design records (`design/`)

Each records the decisions and trade-offs behind a feature; the status line at the
top says whether it shipped and in which core release. They are not setup guides.

Design records for the paid modules (Share, Intake, DLP, legal hold/retention, …) and the
Pro add-on are kept in the owner's private repo `fluxfiles-private-docs`; code comments
cite them as `fluxfiles-private-docs: design/<FILE>.md`. User-facing docs for those
modules stay public (`guides/FEATURES.md`, `reference/CONFIG.md`).

- [ACL role presets](design/ACL-ROLE-PRESETS-DESIGN.md) — implemented in core-v0.2.80.
- [Companion embed](design/COMPANION-EMBED-DESIGN.md) — **design, not implemented** (deferred 2026-11-08: Google OAuth verification).
- [Compliance scorecard](design/COMPLIANCE-SCORECARD-DESIGN.md) — implemented in core-v0.2.82.
- [Connection test (AI + storage)](design/CONNECTION-TEST-DESIGN.md) — implemented in core-v1.6.0.
- [DB storage backend](design/DB-STORAGE-MIGRATION-DESIGN.md) — implemented in core-v0.2.79/0.2.80.
- [License-expiry notifications](design/LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md) — implemented in core-v0.2.84.
- [Licence key refresh](design/LICENSE-KEY-REFRESH-DESIGN.md) — implemented (server + core client in core-v1.2.0).
- [Multi-prefix tokens](design/MULTI-PREFIX-TOKEN-DESIGN.md) — **draft, not implemented**, awaiting decisions.
- [Python token SDK](design/PYTHON-TOKEN-SDK-DESIGN.md) — implemented in python-v0.1.0.
- [Teammate folder sharing](design/TEAMMATE-FOLDER-SHARING-DESIGN.md) — pattern documentation, nothing to implement.

Older design records predate core-v1.1.0, which removed `packages/core/api/PublicLinks.php` and
turned `public/share.html` / `public/intake.html` into redirect stubs (the Share and Intake modules
now serve those pages). Each affected record carries a path note under its status line.

## Security records (`security/`)

- Point-in-time audits (historical; fixes are in `CHANGELOG.md`):
  [free-core audit 2026-09](security/FREE-CORE-SECURITY-AUDIT-2026-09.md),
  [code audit 2026-09-21](reference/AUDIT-2026-09-21.md),
  [second-pass audit 2026-10-14](security/SECURITY-AUDIT-2026-10.md).

## Not in git

A few planning notes are kept out of git as the maintainer's local notes; any link to
one from a tracked doc only resolves on the maintainer's machine.
`docs/update-server.example.php` (reference update server) and `docs/testdata/`
(cross-language token/BYOB vectors) are tracked.
