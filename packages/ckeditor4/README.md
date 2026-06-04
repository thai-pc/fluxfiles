# FluxFiles — CKEditor 4 Plugin

Adds a **FluxFiles** toolbar button to CKEditor 4 for browsing and inserting files.

## Requirements

- CKEditor 4
- A running **FluxFiles core** server (the PHP backend / standalone app in `packages/core`). This plugin is only the editor button — it loads the FluxFiles SDK (`fluxfiles.js`) from that server and `endpoint` must point at it.

## Installation

```bash
npm install @fluxfiles/ckeditor4
```

CKEditor 4 loads plugins from a path, so make the files reachable by your editor —
either point `CKEDITOR.plugins.addExternal` at `node_modules/@fluxfiles/ckeditor4/`,
or copy the plugin folder into your CKEditor `plugins/` directory:

```
ckeditor/plugins/fluxfiles/
├── plugin.js        # readable source
├── plugin.min.js    # minified (~1.3 KB) — use in production
└── README.md
```

A minified `plugin.min.js` ships alongside the source (CDN `unpkg`/`jsdelivr`
resolve to it); regenerate with `npm run build` (esbuild). The toolbar icon is an
inline SVG (no separate image file) — the same folder glyph the TinyMCE plugin uses.

2. Load the FluxFiles SDK (`fluxfiles.js`) on the page.

3. Enable the plugin:

```js
CKEDITOR.replace('editor', {
    extraPlugins: 'fluxfiles',
    fluxfiles: {
        endpoint: 'https://your-fluxfiles-host',
        token: 'JWT_TOKEN',
        disk: 'local',
        locale: 'en',
        multiple: false
    }
});
```

## How It Works

- Click the **FluxFiles** toolbar button (folder icon) in the **Insert** toolbar group.
- The FluxFiles picker opens as a modal overlay.
- Select a file — images are inserted as `<img>`, other files as `<a>` links.
- The modal closes automatically after selection.

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `endpoint` | string | `''` | FluxFiles server URL |
| `token` | string | `''` | JWT authentication token |
| `disk` | string | `'local'` | Storage disk |
| `locale` | string | `null` | UI language code |
| `multiple` | boolean | `false` | Allow multi-file selection |

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#ckeditor-4`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
