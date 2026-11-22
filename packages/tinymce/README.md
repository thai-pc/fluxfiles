# FluxFiles — TinyMCE Plugin

Adds a **FluxFiles** toolbar button to TinyMCE 4 to 8 for browsing and inserting files.

## Requirements

- TinyMCE 4, 5, 6, 7 or 8. Self-hosted TinyMCE 7 and later also needs `license_key: 'gpl'` (or your commercial key) in `tinymce.init`; that is a TinyMCE requirement, not a FluxFiles one.
- A running **FluxFiles core** server (the PHP backend / standalone app in `packages/core`). This plugin is only the editor button: your page must load the FluxFiles SDK (`fluxfiles.js`, served by that server at `/fluxfiles.js`) before TinyMCE initialises, and `fluxfiles_endpoint` must point at that server.

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
├── plugin.min.js    # minified (~5.4 KB) — use in production
└── README.md
```

A minified `plugin.min.js` ships alongside the source (CDN `unpkg`/`jsdelivr`
resolve to it); regenerate with `npm run build` (esbuild).

2. Load the FluxFiles SDK (`fluxfiles.js`) on the page.

3. Enable the plugin:

```js
tinymce.init({
    selector: '#editor',
    plugins: 'fluxfiles link image',
    toolbar: 'undo redo | bold italic | fluxfiles | link image',
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
| `fluxfiles_allowed_types` | string[] | `null` | Extensions (`.pdf` or `pdf`) or MIME types / patterns (`image/*`, `application/pdf`). Shown only in picker mode: non-matching files are hidden and cannot be selected. UI convenience, not a security boundary (the token's `allowed_ext` claim is the server-side check) |
| `fluxfiles_path` | string | `null` | Folder the picker opens in |
| `fluxfiles_max_upload_mb` | number | `null` | Max size per uploaded file, MB |
| `fluxfiles_max_files` | number | `null` | Max files per upload batch |
| `fluxfiles_image_dialog` | boolean | `true` | Wire `file_picker_callback` into the native image/link/media dialogs |

**Files with no permanent URL.** Two cases:

- On a gated local disk (`FLUXFILES_LOCAL_PRIVATE`) or an SFTP disk without `public_url`, `permanent_url` is `null` and the only link is an expiring `/api/fm/img|stream?token=` URL. The plugin never saves that into content: it shows one notification listing the refused files. Use a public disk or set `public_url`.
- A private bucket without `public_url` yields a presigned URL (up to 24h). That one **is** still inserted, with a `console.warn`, and it stops working when it expires.

Only `http(s)` URLs are ever inserted.

## Compatibility

- **TinyMCE 4** — uses the `addButton` / `addMenuItem` API.
- **TinyMCE 5, 6, 7, 8** — use the `ui.registry.addButton` / `ui.registry.addMenuItem` API.
- The version is detected when the plugin loads.

Checked in a real browser on 2026-11-20 against FluxFiles core 1.6.3 with TinyMCE 6.8.6, 7.9.3 and 8.10.2: the toolbar button opens the picker and inserts the image with `alt`, `width` and `height`, and the browse icon in TinyMCE's own *Insert/edit image* dialog fills Source, Alt, Width and Height.

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#framework-adapters`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
