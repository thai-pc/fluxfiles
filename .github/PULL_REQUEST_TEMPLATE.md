## What and why

<!-- What does this change, and what problem does it solve? Link the issue if there is one (Fixes #123). -->

## How it was tested

<!-- The suites you ran, e.g. the core unit + integration loop, the package's npm test, an adapter smoke. -->

## Checklist

- [ ] Tests added or updated for behaviour changes, and the suites for every touched package pass
- [ ] A new or renamed token claim or env var is documented in `docs/reference/CONFIG.md`
- [ ] New UI strings are in every locale under `packages/core/lang/`
- [ ] Docs (README, guide, package README) describe the change
- [ ] No secrets, tokens, `.env` files or runtime data (`storage/`, `_fluxfiles/`) are committed
- [ ] No paid-module code or design specs (those live in separate private packages)
- [ ] No hand edits to `dist/` files, version bumps or release tags
