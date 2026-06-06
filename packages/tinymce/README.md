# FluxFiles — TinyMCE Plugin

Adds a **FluxFiles** toolbar button to TinyMCE 4.x / 5.x for browsing and inserting files.

## Requirements

- TinyMCE 4.x or 5.x
- A running **FluxFiles core** server (the PHP backend / standalone app in `packages/core`). This plugin is only the editor button — it loads the FluxFiles SDK (`fluxfiles.js`) from that server and `fluxfiles_endpoint` must point at it.

## Installation

```bash
npm install @fluxfiles/tinymce
```

TinyMCE loads the plugin from a URL (`external_plugins`), so make `plugin.js`
reachable — reference it from `node_modules/@fluxfiles/tinymce/plugin.js`, or copy
the plugin folder into your TinyMCE `plugins/` directory:

```
tinymce/plugins/fluxfiles/
├── plugin.js        # readable source
├── plugin.min.js    # minified (~1.8 KB) — use in production
└── README.md
```

A minified `plugin.min.js` ships alongside the source (CDN `unpkg`/`jsdelivr`
resolve to it); regenerate with `npm run build` (esbuild).

2. Load the FluxFiles SDK (`fluxfiles.js`) on the page.

3. Enable the plugin:

```js
tinymce.init({
    selector: '#editor',
    plugins: 'fluxfiles',
    toolbar: 'undo redo | formatselect | bold italic | fluxfiles | link image',
    fluxfiles_endpoint: 'https://your-fluxfiles-host',
    fluxfiles_token: 'JWT_TOKEN',
    fluxfiles_disk: 'local',
    fluxfiles_locale: 'en',
    fluxfiles_multiple: false
});
```

## How It Works

- Click the **FluxFiles** toolbar button (folder/browse icon).
- The FluxFiles picker opens as a modal overlay.
- Select a file — images are inserted as `<img>` (with `alt` from the file's
  metadata and `width`/`height` when known), other files as `<a>` links.
- The modal closes automatically after selection.

> **Embedding & expiring URLs.** On a **private** disk the file URL is a
> *presigned* URL that expires (≤ 24h) — embedding it in saved content will break
> once it expires (the plugin logs a `console.warn`). For editor embeds use a
> **public** disk or a `public_url` so the inserted URL is permanent.

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `fluxfiles_endpoint` | string | `''` | FluxFiles server URL |
| `fluxfiles_token` | string | `''` | JWT authentication token |
| `fluxfiles_disk` | string | `'local'` | Storage disk |
| `fluxfiles_locale` | string | `null` | UI language code |
| `fluxfiles_multiple` | boolean | `false` | Allow multi-file selection |

## Compatibility

- **TinyMCE 4.x** — Uses `addButton` / `addMenuItem` API
- **TinyMCE 5.x** — Uses `ui.registry.addButton` / `ui.registry.addMenuItem` API
- Auto-detects version at load time.

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#tinymce-4x--5x`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
