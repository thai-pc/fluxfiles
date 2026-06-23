# Real-adapter e2e (Playwright)

This is the rig for debugging the **embedded FluxFiles UI through the real framework
adapters** (not the standalone `/public` UI — that's `packages/core/tests/browser/`).
Each suite boots a live core backend and drives the *actual* wrapper embedding it.

| Adapter | How it's exercised |
|---|---|
| **React** | Vite host renders `@fluxfiles/react` `<FluxFiles>` → live core iframe (port 8088). |
| **Vue** | Vite host renders `@fluxfiles/vue` `<FluxFiles>` → live core iframe. |
| **Laravel** | Real Laravel app (proxy mode): `<x-fluxfiles>` mints a JWT server-side, SDK embed, `/api/fm/*` proxied through `FluxFilesController` → core. |
| **WordPress** | Real plugin (the `build-wordpress.sh` artifact) in `@wordpress/env`: `[fluxfiles]` shortcode → REST proxy `/wp-json/fluxfiles/v1/api/fm/*` → core. |

## Run

```bash
cd packages/core/tests/apps
npm install                       # once

# React + Vue + Laravel (one Playwright run; boots core + both Vite hosts + artisan serve)
npm run setup:laravel             # once — scaffolds the gitignored laravel-app
npm run e2e

# WordPress (Docker; separate because wp-env is a long-lived stack)
npm run setup:wp                  # once per machine — builds the plugin + starts wp-env
npm run e2e:wp
# teardown: npx @wordpress/env stop   (or `destroy` to wipe)
```

## Layout

- `react-host/`, `vue-host/` + `*.vite.config.mjs` — Vite hosts (read `?token=`/`?endpoint=`).
- `e2e/` — Playwright config + specs for React/Vue/Laravel (`helpers.ts`, `secret.ts`,
  `global-setup/teardown.ts` write/restore the core `.env`).
- `laravel-e2e/` — committed source for the Laravel app (`setup.sh` + `files/`);
  the generated `laravel-app/` is gitignored.
- `wordpress-e2e/` — `.wp-env.json`, `setup.sh`, `playwright.config.ts`, `wp.spec.ts`.
