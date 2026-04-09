# FluxFiles — CKEditor 4 Plugin

Adds a **FluxFiles** toolbar button to CKEditor 4 for browsing and inserting files.

## Installation

1. Copy this folder to your CKEditor plugins directory:

```
ckeditor/plugins/fluxfiles/
├── plugin.js
├── icons/
│   └── fluxfiles.png
└── README.md
```

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
