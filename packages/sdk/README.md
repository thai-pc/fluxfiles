# FluxFiles SDK (JavaScript)

Vanilla JavaScript SDK for embedding FluxFiles in any web app (no framework required).

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

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#javascript-sdk-vanilla`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`

