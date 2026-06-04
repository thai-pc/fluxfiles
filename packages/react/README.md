# FluxFiles for React

[![npm](https://img.shields.io/npm/v/@fluxfiles/react?color=61dafb)](https://www.npmjs.com/package/@fluxfiles/react)

React components and hooks for [FluxFiles](https://github.com/thai-pc/fluxfiles) — a standalone, embeddable file manager with multi-storage support (Local, AWS S3, Cloudflare R2).

## Requirements

- React 18 or 19
- A running **FluxFiles core service** — the backend that serves the file-manager UI and performs the storage operations (the PHP app in `packages/core`, e.g. via the Docker image). This package is a thin client; `endpoint` points at it.
- A user **token**, minted server-side. Your own backend can be anything: use [`@fluxfiles/node`](https://www.npmjs.com/package/@fluxfiles/node) to issue tokens from Node/Next.js (**no PHP needed**), or the Laravel / WordPress adapters.

## Installation

```bash
npm install @fluxfiles/react
# or
yarn add @fluxfiles/react
```

## Next.js

Works in Next.js the same way as any React app — the components are SSR-safe
(they only touch `window`/`document` inside effects) and ship the `"use client"`
directive, so you can render them in the **App Router** without writing your own
client wrapper:

```tsx
// app/files/page.tsx — a Server Component can render <FluxFiles> directly
import { FluxFiles } from '@fluxfiles/react';
import { mintToken } from '@/lib/fluxfiles'; // your server-side token mint

export default async function Page() {
  const token = await mintToken();
  return (
    <FluxFiles endpoint="https://your-fluxfiles-host" token={token} disk="local" />
  );
}
```

The **Pages Router** works identically. Always mint the JWT **on the server**
(never expose your `FLUXFILES_SECRET` to the browser) — e.g. in a Route Handler /
API route, or by asking your FluxFiles core backend for one — and pass it in as
`token`.

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
| `multiple` | `boolean` | No | When true, onSelect receives array of files |
| `maxUploadMb` | `number` | No | Max size per uploaded file, in **MB** (deprecated alias: `maxSize` in bytes) |
| `maxFiles` | `number` | No | Max files per upload batch (0/omit = unlimited; server enforces the prefix total via `max_files`) |
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
