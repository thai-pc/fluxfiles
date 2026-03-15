# FluxFiles for React

[![npm](https://img.shields.io/npm/v/@fluxfiles/react?color=61dafb)](https://www.npmjs.com/package/@fluxfiles/react)

React components and hooks for [FluxFiles](https://github.com/thai-pc/fluxfiles) — a standalone, embeddable file manager with multi-storage support (Local, AWS S3, Cloudflare R2).

## Requirements

- React 18 or 19
- A running FluxFiles backend

## Installation

```bash
npm install @fluxfiles/react
# or
yarn add @fluxfiles/react
```

## Components

### FluxFilesModal

File picker as a modal overlay:

```tsx
import { useState } from 'react';
import { FluxFilesModal } from '@fluxfiles/react';

function App() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button onClick={() => setOpen(true)}>Pick file</button>

            <FluxFilesModal
                open={open}
                endpoint="https://your-fluxfiles-host"
                token={token}
                disk="local"
                locale="en"
                onSelect={(file) => {
                    console.log(file.url, file.path);
                    setOpen(false);
                }}
                onClose={() => setOpen(false)}
            />
        </>
    );
}
```

### FluxFiles

Embedded file manager (inline, no modal):

```tsx
import { useRef } from 'react';
import { FluxFiles, FluxFilesHandle } from '@fluxfiles/react';

function App() {
    const ref = useRef<FluxFilesHandle>(null);

    return (
        <FluxFiles
            ref={ref}
            endpoint="https://your-fluxfiles-host"
            token={token}
            disk="local"
            width="100%"
            height="600px"
            onSelect={(file) => console.log(file)}
        />
    );
}
```

## Hook

### useFluxFiles

Full control over the iframe communication:

```tsx
import { useFluxFiles } from '@fluxfiles/react';

function App() {
    const { iframeRef, iframeSrc, navigate, setDisk, refresh, search, aiTag } =
        useFluxFiles({
            endpoint: 'https://your-fluxfiles-host',
            token,
            onSelect: (file) => console.log(file),
            onEvent: (event) => console.log(event.action),
        });

    return (
        <>
            <button onClick={() => navigate('/photos')}>Photos</button>
            <button onClick={() => setDisk('s3')}>Switch to S3</button>
            <button onClick={() => refresh()}>Refresh</button>
            <button onClick={() => search('invoice')}>Search</button>

            <iframe ref={iframeRef} src={iframeSrc} style={{ width: '100%', height: '600px', border: 'none' }} />
        </>
    );
}
```

## Commands

All components and the hook expose these methods:

| Method | Description |
|--------|-------------|
| `navigate(path)` | Navigate to a directory |
| `setDisk(disk)` | Switch storage disk |
| `refresh()` | Reload current directory |
| `search(query)` | Full-text search |
| `crossCopy(disk, path?)` | Copy selection to another disk |
| `crossMove(disk, path?)` | Move selection to another disk |
| `aiTag()` | AI auto-tag selected image |

## Props

| Prop | Type | Required | Description |
|------|------|----------|-------------|
| `endpoint` | `string` | Yes | FluxFiles server URL |
| `token` | `string` | Yes | JWT token |
| `disk` | `string` | No | Initial storage disk (`local`) |
| `mode` | `string` | No | `picker` or `browser` |
| `locale` | `string` | No | UI language code |
| `width` | `string` | No | Iframe width |
| `height` | `string` | No | Iframe height |
| `onSelect` | `(file) => void` | No | File selected callback |
| `onEvent` | `(event) => void` | No | Action event callback |
| `onClose` | `() => void` | No | Modal closed callback |
| `open` | `boolean` | No | Modal visibility (FluxFilesModal only) |

## TypeScript

All types are exported:

```ts
import type { FluxFile, FluxEvent, FluxFilesConfig, FluxFilesHandle } from '@fluxfiles/react';
```

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- [FluxFiles](https://github.com/thai-pc/fluxfiles) — Main repository
- [Documentation](https://github.com/thai-pc/fluxfiles#react) — Full docs
- [Issues](https://github.com/thai-pc/fluxfiles/issues) — Bug reports
