# Security Policy

## Reporting a vulnerability

Please report vulnerabilities **privately** through GitHub:
[Report a vulnerability](https://github.com/thai-pc/fluxfiles/security/advisories/new).
Do not open a public issue for security problems. Include the affected package and
version, reproduction steps, and the impact you observed.

## Supported versions

Fixes are made against the **latest release** of each line:

- core (`core-v*`)
- Laravel adapter (`laravel-v*`)
- WordPress plugin (`wordpress-v*`)

Older releases are not patched; upgrade to the latest. Other adapters, SDKs and the
paid modules follow the latest release of their own package.

## What to expect

This is a small project, so these are best-effort targets: acknowledgement within
about 5 working days, and a fix or mitigation plan for confirmed issues as soon as
practical. We publish a GitHub security advisory (with credit, if you want it) when a
fix ships.

## Scope

In scope: the core API and UI (`packages/core`), the Laravel and WordPress adapters, the
React/Vue/CKEditor/TinyMCE/Summernote wrappers, the Node/Python token SDKs, the MCP
server, and the paid modules (share, intake, versioning, webhooks, and the rest).

Out of scope: misconfiguration of your own deployment (e.g. serving `_fluxfiles/`
without a deny rule, a weak `FLUXFILES_SECRET`), and issues in third-party
dependencies that are not reachable through FluxFiles.
