# FluxFiles documentation index

The `docs/` directory intentionally keeps stable, linkable filenames. Avoid moving
existing documents merely to make the tree look tidier: package READMEs, release
notes, and external links point at these paths. Use this index to navigate the
different document types instead.

## Start here

- [Configuration reference](reference/CONFIG.md) — JWT claims and server environment.
- [Deployment](guides/DEPLOYMENT.md) — self-hosting, web-server configuration, and upload limits.
- [API reference](guides/API.md) — standalone HTTP routes.
- [Architecture](reference/ARCHITECTURE.md) — monorepo and integration topology.
- [Feature deep dives](guides/FEATURES.md) — image, watermark, usage, and terminal behavior.

## Operators

- [Activation](guides/ACTIVATE.md) — install and enable paid modules.
- [Operations runbook](guides/OPERATIONS.md) — licence/update infrastructure for sellers.
- [DB storage migration](design/DB-STORAGE-MIGRATION-DESIGN.md) — JSON-to-DB backend design and rollout.
- [Git deploy security review](security/GIT-DEPLOY-SECURITY-REVIEW.md)

## Design and security records

Files named `*-DESIGN.md`, `*-SPEC.md`, and `*-SECURITY-REVIEW.md` record design
decisions, trade-offs, and implementation detail. They are not the primary
operator setup path; link user-facing setup material from the guides above.

When adding a new document, prefer a stable descriptive name in this directory,
add it to the appropriate section here, and link it from the relevant guide. If
the collection grows substantially, introduce folders only for new documents and
leave forwarding stubs at existing paths.
