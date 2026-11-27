# FluxFiles SDK (JavaScript)

Vanilla JavaScript SDK for embedding FluxFiles in any web app (no framework required).

## Requirements

- A running **FluxFiles core service** — the backend that serves the UI and performs the storage operations (the PHP app in `packages/core`, e.g. via the Docker image). This SDK is a thin client: it loads the file-manager UI from it in an iframe, and that UI talks to its API at `endpoint`.
- A user **token**, minted server-side. Your own backend can be anything: use [`@fluxfiles/node`](https://www.npmjs.com/package/@fluxfiles/node) to issue tokens from Node (**no PHP needed**), or the Laravel / WordPress adapters.

- **Same secret, allowed origins.** Your token backend must use the same `FLUXFILES_SECRET` (>= 32 bytes) as the core server, and the server needs `FLUXFILES_ALLOWED_ORIGINS` + `FLUXFILES_FRAME_ANCESTORS` set to your app's exact origin (scheme + host + port); with neither set, any site can frame the UI. See [CONFIG.md](https://github.com/thai-pc/fluxfiles/blob/master/docs/reference/CONFIG.md#4-server-env-vars-server-wide). A token minted without explicit claims is read-only on the `local` disk.

## Install (npm)

```bash
npm install fluxfiles
```

## Usage

```html
<div id="fluxfiles" style="height:600px"></div>
<script src="https://your-fluxfiles-host/fluxfiles.js"></script>
<script>
FluxFiles.open({
  endpoint: "https://your-fluxfiles-host",
  token: "JWT_TOKEN",
  disk: "local",
  container: "#fluxfiles",
});
</script>
```

### Minified build

The package also ships a minified `fluxfiles.min.js` (~6 KB, less than half the size) for
production. Same global API:

```html
<script src="https://your-fluxfiles-host/fluxfiles.min.js"></script>
<!-- or via CDN (resolves to fluxfiles.min.js automatically): -->
<script src="https://cdn.jsdelivr.net/npm/fluxfiles"></script>
```

It's regenerated from `fluxfiles.js` with `npm run build` (esbuild).

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles/blob/master/docs/guides/GUIDE.md#javascript-sdk-vanilla`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`

