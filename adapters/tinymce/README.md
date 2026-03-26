# FluxFiles — TinyMCE Plugin

Adds a **FluxFiles** toolbar button to TinyMCE 4.x / 5.x for browsing and inserting files.

## Installation

1. Copy this folder to your TinyMCE plugins directory:

```
tinymce/plugins/fluxfiles/
├── plugin.js
└── README.md
```

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
- Select a file — images are inserted as `<img>`, other files as `<a>` links.
- The modal closes automatically after selection.

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
