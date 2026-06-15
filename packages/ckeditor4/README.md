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

Two ways to pick a file:

1. **Toolbar button** — click the **FluxFiles** button (folder icon) in the
   **Insert** group. The picker opens; the selection is inserted directly
   (`<img>` for images with `alt` from metadata and `width`/`height` when known,
   other files as `<a>` links).
2. **Native Image dialog** — the plugin injects a **Browse FluxFiles** button into
   CKEditor's own *Image Properties* dialog. Clicking it opens FluxFiles inline
   (no popup) and fills the dialog's **URL + Alternative Text + Width + Height**,
   so you can set border/alignment/etc. in the native dialog before inserting
   (the "Browse Server" / CKFinder pattern).

The modal closes automatically after selection.

> Disable the native Image-dialog button with `fluxfiles: { imageDialog: false }`.

> **Embedding & expiring URLs.** On a **private** disk the file URL is a
> *presigned* URL that expires (≤ 24h) — embedding it in saved content will break
> once it expires (the plugin logs a `console.warn`). For editor embeds use a
> **public** disk or a `public_url` so the inserted URL is permanent.

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `endpoint` | string | `''` | FluxFiles server URL |
| `token` | string | `''` | JWT authentication token |
| `disk` | string | `'local'` | Storage disk |
| `locale` | string | `null` | UI language code |
| `multiple` | boolean | `false` | Allow multi-file selection |
| `imageDialog` | boolean | `true` | Inject the **Browse FluxFiles** button into the native Image dialog |

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#ckeditor-4`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
