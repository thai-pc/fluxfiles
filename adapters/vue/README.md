# FluxFiles for Vue / Nuxt

[![npm](https://img.shields.io/npm/v/@fluxfiles/vue?color=42b883)](https://www.npmjs.com/package/@fluxfiles/vue)

Vue 3 components and composables for [FluxFiles](https://github.com/thai-pc/fluxfiles) — a standalone, embeddable file manager with multi-storage support (Local, AWS S3, Cloudflare R2).

## Requirements

- Vue 3.3+
- A running FluxFiles backend

## Installation

```bash
npm install @fluxfiles/vue
# or
yarn add @fluxfiles/vue
```

## Components

### FluxFilesModal

File picker as a modal overlay:

```vue
<script setup>
import { ref } from 'vue';
import { FluxFilesModal } from '@fluxfiles/vue';

const open = ref(false);

function onSelect(file) {
    console.log(file.url, file.path);
    open.value = false;
}
</script>

<template>
    <button @click="open = true">Pick file</button>

    <FluxFilesModal
        v-model:open="open"
        endpoint="https://your-fluxfiles-host"
        :token="token"
        disk="local"
        locale="en"
        @select="onSelect"
        @close="open = false"
    />
</template>
```

### FluxFiles

Embedded file manager (inline, no modal):

```vue
<script setup>
import { ref } from 'vue';
import { FluxFiles } from '@fluxfiles/vue';

const fm = ref();
</script>

<template>
    <FluxFiles
        ref="fm"
        endpoint="https://your-fluxfiles-host"
        :token="token"
        disk="local"
        width="100%"
        height="600px"
        @select="(file) => console.log(file)"
    />
</template>
```

Programmatic control via template ref:

```ts
fm.value?.navigate('/photos');
fm.value?.setDisk('s3');
fm.value?.refresh();
fm.value?.search('invoice');
```

## Composable

### useFluxFiles

Full control over the iframe communication:

```ts
import { useFluxFiles } from '@fluxfiles/vue';

const { iframeRef, iframeSrc, navigate, setDisk, refresh, search, aiTag } =
    useFluxFiles({
        endpoint: 'https://your-fluxfiles-host',
        token,
        onSelect: (file) => console.log(file),
        onEvent: (event) => console.log(event.action),
    });
```

```vue
<template>
    <button @click="navigate('/photos')">Photos</button>
    <button @click="setDisk('s3')">Switch to S3</button>
    <button @click="refresh()">Refresh</button>

    <iframe ref="iframeRef" :src="iframeSrc" style="width:100%;height:600px;border:none;" />
</template>
```

## Nuxt 3

Auto-import components globally — add the plugin to `nuxt.config.ts`:

```ts
export default defineNuxtConfig({
    plugins: ['@fluxfiles/vue/nuxt'],
});
```

`<FluxFiles>` and `<FluxFilesModal>` are then available in all pages and components without importing.

## Commands

All components and the composable expose these methods:

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

## Events

| Event | Payload | Description |
|-------|---------|-------------|
| `@select` | `FluxFile` | File selected |
| `@event` | `FluxEvent` | Action event (upload, delete, move...) |
| `@close` | — | Modal closed |

## TypeScript

All types are exported:

```ts
import type { FluxFile, FluxEvent, FluxFilesConfig, FluxFilesHandle } from '@fluxfiles/vue';
```

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- [FluxFiles](https://github.com/thai-pc/fluxfiles) — Main repository
- [Documentation](https://github.com/thai-pc/fluxfiles#vue--nuxt) — Full docs
- [Issues](https://github.com/thai-pc/fluxfiles/issues) — Bug reports
