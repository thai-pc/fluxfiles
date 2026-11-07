# FluxFiles — CKEditor 5 Plugin

Adds a **FluxFiles** toolbar button to CKEditor 5 for browsing and inserting files, plus an
"Insert image via FluxFiles" entry in the image toolbar's insert dropdown.

## Requirements

- CKEditor 5 **42 or newer**, from the unified `ckeditor5` package (peer dependency, not bundled).
- A running **FluxFiles core** server (the PHP backend / standalone app in `packages/core`). `endpoint` must point at it.
- ESM only, like `ckeditor5` itself.

> **CKEditor 5 licensing.** CKEditor 5 is GPL-2.0-or-later or commercial, and the editor needs a
> `licenseKey` (`'GPL'` for open-source use, or your commercial key). That is the editor's
> requirement, set on the editor config. This plugin is MIT, embeds no key and never reads one.

## Installation

```bash
npm install @fluxfiles/ckeditor5
```

(`fluxfiles`, the browser SDK, is installed with it. If your page already loads `fluxfiles.js`
as a global, that copy is used instead.)

```js
import { ClassicEditor, Essentials, Paragraph, Image, ImageInsert, Link } from 'ckeditor5';
import { FluxFiles } from '@fluxfiles/ckeditor5';
import 'ckeditor5/ckeditor5.css';

ClassicEditor.create(document.querySelector('#editor'), {
    licenseKey: 'GPL',
    plugins: [Essentials, Paragraph, Image, ImageInsert, Link, FluxFiles],
    toolbar: ['undo', 'redo', '|', 'insertImage', 'link', 'fluxfiles'],
    fluxfiles: {
        endpoint: 'https://your-fluxfiles-host',
        token: 'JWT_TOKEN',          // or: token: () => fetch('/fluxfiles-token').then(r => r.text())
        disk: 'local',
        locale: 'en',
        multiple: true,
        onTokenRefresh: async () => (await fetch('/fluxfiles-token')).text(),
    },
});
```

## How It Works

1. **Toolbar button** `fluxfiles` (command `fluxfiles`): opens the picker and inserts the
   selection at the caret.
   - Images become image elements with `src`, and `alt` from the file's alt text (file name if
     none). When the payload carries `img_srcset` (WebP/AVIF responsive images enabled on the
     token) and the image plugin allows a `srcset` attribute, it is added too.
   - Other files become links with the metadata title (or file name) as text.
   - With `multiple: true`, every selected file is inserted in order. Folders are skipped.
   - Without the image plugin, images are inserted as links; without the link plugin, as plain text.
2. **Image insert dropdown**: when `ImageInsert` is loaded, the image toolbar's insert dropdown
   offers FluxFiles next to "Insert image via URL". Turn it off with `imageInsert: false`.

The main JWT is never put in a URL: the SDK hands it to the picker iframe with `postMessage`
and checks the iframe's origin on every message.

> **Embedding and expiring URLs.** On a **private** disk the file URL is a *presigned* URL that
> expires, so embedding it in saved content breaks later (the plugin logs a `console.warn`).
> Use a **public** disk or a `public_url`. A preview-only selection (no embeddable URL, e.g. a
> watermark overlay with `allow_download=false`) is refused, as in the other editor plugins.

> CKEditor 5's image model has no `sizes` attribute and writes `sizes="100vw"` itself, so
> `img_sizes` is only used by a custom image plugin that registers one.

## Configuration (`config.fluxfiles`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `endpoint` | string | `''` | FluxFiles server URL |
| `token` | string \| () => string \| Promise\<string\> | `''` | JWT, or a getter called each time the picker opens |
| `disk` | string | `'local'` | Storage disk |
| `disks` | string[] | | Disks shown in the picker sidebar |
| `path` | string | | Folder the picker opens in |
| `locale` | string | | UI language code |
| `theme` | string | | `light` / `dark` / `auto` |
| `multiple` | boolean | `false` | Allow multi-file selection |
| `allowedTypes` | string[] | | Extensions (`.pdf` or `pdf`) or MIME types / patterns (`image/*`). Picker mode only: non-matching files are hidden and cannot be selected. UI convenience, not a security boundary (the token's `allowed_ext` claim is the server-side check) |
| `maxUploadMb` | number | | Max size per uploaded file, MB |
| `maxFiles` | number | | Max files per upload batch |
| `onTokenRefresh` | (ctx) => Promise\<string \| null\> | | Called when the picker gets a 401; return a fresh JWT |
| `imageInsert` | boolean | `true` | Offer FluxFiles in the image insert dropdown |
| `onInsert` | (files) => void | | Called after files were inserted |

**Files with no permanent URL.** On a private or gated disk (`FLUXFILES_LOCAL_PRIVATE`, SFTP, or a private bucket without `public_url`) `permanent_url` is `null` and the only link is an expiring `/api/fm/img|stream?token=` URL. The plugin never saves those into content: it shows a notification explaining the file was not inserted. Use a public disk or set `public_url`. Only `http(s)` URLs are ever inserted.

## API

- Plugin: `FluxFiles` (`pluginName: 'FluxFiles'`), named and default export
- Command: `fluxfiles` (`editor.execute('fluxfiles')`)
- Toolbar item: `fluxfiles`
- Types: `FluxFilesConfig`; `config.fluxfiles` is typed on `EditorConfig`

## Development

```bash
npm install
npm test          # vitest, drives a real ClassicEditor in jsdom
npm run typecheck
npm run build     # tsup -> dist/
```

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#framework-adapters`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
