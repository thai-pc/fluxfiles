# FluxFiles SDK (JavaScript)

Vanilla JavaScript SDK for embedding FluxFiles in any web app (no framework required).

## Requirements

- A running **FluxFiles core service** — the backend that serves the UI and performs the storage operations (the PHP app in `packages/core`, e.g. via the Docker image). This SDK is a thin client: it loads `fluxfiles.js` from it and talks to its API at `endpoint`.
- A user **token**, minted server-side. Your own backend can be anything: use [`@fluxfiles/node`](https://www.npmjs.com/package/@fluxfiles/node) to issue tokens from Node (**no PHP needed**), or the Laravel / WordPress adapters.

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

The package also ships a minified `fluxfiles.min.js` (~5 KB, half the size) for
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
- Documentation: `https://github.com/thai-pc/fluxfiles#javascript-sdk-vanilla`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`

