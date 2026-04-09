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

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#sdk`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`

