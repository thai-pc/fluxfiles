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

Two ways to pick a file:

1. **Toolbar button** — click the **FluxFiles** button (folder/browse icon). The
   picker opens; the selection is inserted directly (`<img>` for images with `alt`
   from metadata and `width`/`height` when known, other files as `<a>` links).
2. **Native Insert/Edit Image dialog** — the plugin registers a
   `file_picker_callback`, so the **Source** field of TinyMCE's own image dialog
   (and the link/media dialogs) gets a *browse* icon. Clicking it opens FluxFiles
   and fills the dialog's **Source + Alt + Width + Height**, so you can fine-tune
   the image in the familiar native dialog before inserting (the "Browse Server" /
   CKFinder pattern — no popup window).

The modal closes automatically after selection.

> The plugin only sets `file_picker_callback` if you haven't provided your own.
> Disable the native-dialog integration with `fluxfiles_image_dialog: false`.

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
| `fluxfiles_disks` | string[] | `null` | Disks shown in the picker sidebar |
| `fluxfiles_locale` | string | `null` | UI language code |
| `fluxfiles_theme` | string | `null` | Picker theme: `light` / `dark` / `auto` |
| `fluxfiles_multiple` | boolean | `false` | Allow multi-file selection |
| `fluxfiles_image_dialog` | boolean | `true` | Wire `file_picker_callback` into the native image/link/media dialogs |

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
