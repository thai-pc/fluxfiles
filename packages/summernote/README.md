# FluxFiles — Summernote Plugin

Adds a **FluxFiles** toolbar button to [Summernote](https://summernote.org/) for browsing and inserting files.

## Requirements

- jQuery + Summernote loaded **before** this plugin.
- A running **FluxFiles core** server (the PHP backend / standalone app in `packages/core`). This plugin is only the editor button — it loads the FluxFiles SDK (`fluxfiles.js`) from that server and the `endpoint` option must point at it.

## Installation

```bash
npm install @fluxfiles/summernote
```

Summernote plugins are loaded as a `<script>` after Summernote itself, so make
`plugin.js` reachable — reference it from `node_modules/@fluxfiles/summernote/plugin.js`,
copy the folder next to your Summernote assets, or load it from a CDN:

```html
<!-- jQuery, then Summernote, then the FluxFiles SDK + this plugin -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-lite.min.js"></script>

<script src="https://your-fluxfiles-host/fluxfiles.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fluxfiles/summernote/plugin.min.js"></script>
```

A minified `plugin.min.js` ships alongside the source (CDN `unpkg`/`jsdelivr`
resolve to it); regenerate with `npm run build` (esbuild).

## Usage

Add `'fluxfiles'` to a toolbar group and pass a `fluxfiles` options object:

```js
$('#editor').summernote({
    toolbar: [
        ['style', ['bold', 'italic', 'underline']],
        ['insert', ['fluxfiles', 'link']]
    ],
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

- Click the **FluxFiles** toolbar button (folder/browse icon).
- The FluxFiles picker opens as a modal overlay.
- Select a file — images are inserted as `<img>` (with `alt` from the file's
  metadata and `width`/`height` when known), other files as `<a>` links — via
  Summernote's `editor.pasteHTML` (at the cursor).
- The modal closes automatically after selection.

> **Embedding & expiring URLs.** On a **private** disk the file URL is a
> *presigned* URL that expires (≤ 24h) — embedding it in saved content will break
> once it expires (the plugin logs a `console.warn`). For editor embeds use a
> **public** disk or a `public_url` so the inserted URL is permanent. The plugin
> already prefers `permanent_url` automatically when present.

## Configuration

The plugin reads a single `fluxfiles` options object:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `endpoint` | string | `''` | FluxFiles server URL |
| `token` | string | `''` | JWT authentication token |
| `disk` | string | `'local'` | Storage disk |
| `locale` | string | `null` | UI language code |
| `multiple` | boolean | `false` | Allow multi-file selection |
| `maxUploadMb` | number | `null` | Per-file upload limit hint |
| `maxFiles` | number | `null` | Max files hint |

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#summernote`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
