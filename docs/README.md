# FluxFiles documentation index

Documents live in four folders by type. Keep filenames stable once published —
package READMEs, the changelog and external links point at these paths — and add
every new document to the matching section below.

## Start here (guides)

- [Configuration reference](reference/CONFIG.md) — every JWT claim and server env var (the single source of truth; CI-guarded against `Claims.php`).
- [Deployment](guides/DEPLOYMENT.md) — self-hosting, web-server configuration, upload limits.
- [API reference](guides/API.md) — standalone HTTP routes, incl. tus resumable upload and the paid-module routes.
- [Feature deep dives](guides/FEATURES.md) — on-demand WebP/AVIF, srcset, watermark, usage dashboard, the BYO terminal embed.
- [Industry presets](guides/INDUSTRY-PRESETS.md) — copy-paste token configs per vertical.

## Operators and sellers

- [Activation](guides/ACTIVATE.md) — install and enable paid modules, licence keys, SSO setup, error meanings.
- [Operations runbook](guides/OPERATIONS.md) — licence/update infrastructure for whoever sells FluxFiles.

## Reference

- [Architecture](reference/ARCHITECTURE.md) — monorepo layout, integration patterns, publish topology, CI map.
- [Metadata storage](reference/METADATA-STORAGE-DESIGN.md) — how metadata, trash and audit travel with user storage (the default `json` backend).

## Design records (`design/`)

Each records the decisions and trade-offs behind a feature; the status line at the
top says whether it shipped and in which core release. They are not setup guides.

- [ACL role presets](design/ACL-ROLE-PRESETS-DESIGN.md) — implemented in core-v0.2.80.
- [Compliance scorecard](design/COMPLIANCE-SCORECARD-DESIGN.md) — implemented in core-v0.2.82.
- [DB storage backend](design/DB-STORAGE-MIGRATION-DESIGN.md) — implemented in core-v0.2.79/0.2.80.
- [DLP / PII detection](design/DLP-PII-REDACTION-DESIGN.md) — implemented in core-v0.2.82.
- [Feature ideas 2026-10](design/FEATURE-IDEAS-2026-10.md) — idea list with per-item status (Vietnamese).
- [Intake branding + analytics](design/INTAKE-BRANDING-ANALYTICS-DESIGN.md) — implemented in core-v0.2.76.
- [Intake notify-on-receipt](design/INTAKE-NOTIFY-ON-RECEIPT-DESIGN.md) — implemented in core-v0.2.77.
- [License-expiry notifications](design/LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md) — implemented in core-v0.2.84.
- [Multi-prefix tokens](design/MULTI-PREFIX-TOKEN-DESIGN.md) — **draft, not implemented**, awaiting decisions.
- [Operator UI for Share/Intake](guides/OPERATOR-SHARE-INTAKE-UI.md) — implemented in core-v0.2.68 (a spec, kept at its original path under `guides/`).
- [Python token SDK](design/PYTHON-TOKEN-SDK-DESIGN.md) — implemented in python-v0.1.0.
- [Legal hold + retention](design/RETENTION-LEGAL-HOLD-DESIGN.md) — implemented in core-v0.2.82 (retention rules core-v0.2.97).
- [Share analytics](design/SHARE-ANALYTICS-DESIGN.md) — implemented in core-v0.2.75.
- [Share public landing](design/SHARE-PUBLIC-LANDING.md) — implemented in core-v0.2.64.
- [Teammate folder sharing](design/TEAMMATE-FOLDER-SHARING-DESIGN.md) — pattern documentation, nothing to implement.

## Security records (`security/`)

- Point-in-time audits (historical; fixes are in `CHANGELOG.md`):
  [free-core audit 2026-09](security/FREE-CORE-SECURITY-AUDIT-2026-09.md),
  [code audit 2026-09-21](reference/AUDIT-2026-09-21.md),
  [second-pass audit 2026-10-14](security/SECURITY-AUDIT-2026-10.md).
- Archived (features removed in 1.0.0): [Git deploy review](archive/GIT-DEPLOY-SECURITY-REVIEW.md), [SFTP ControlMaster spec](archive/SFTP-CONTROLMASTER-SPEC.md) and [review](archive/SFTP-CONTROLMASTER-SECURITY-REVIEW.md).

## Not in git

`docs/ROADMAP.md`, `docs/COMMERCIAL-STRATEGY.md`, `docs/LICENSING-PLAN.md`,
`docs/LICENSE-SERVER-DEPLOYMENT.md` and `docs/UPDATE-CHANNEL-DEPLOYMENT.md` are the
owner's local notes (gitignored), as are the signing keys under `docs/`. Links to
them from tracked docs only resolve on the owner's machine.
`docs/update-server.example.php` (reference update server) and `docs/testdata/`
(cross-language token/BYOB vectors) are tracked.
