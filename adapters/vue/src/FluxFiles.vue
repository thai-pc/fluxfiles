<script setup lang="ts">
import { computed } from 'vue';
import { useFluxFiles } from './useFluxFiles';
import type { FluxFile, FluxEvent } from './types';

const props = withDefaults(defineProps<{
  endpoint: string;
  token: string;
  disk?: string;
  mode?: 'picker' | 'browser';
  allowedTypes?: string[] | null;
  maxSize?: number | null;
  locale?: string | null;
  width?: string | number;
  height?: string | number;
}>(), {
  disk: 'local',
  mode: 'picker',
  width: '100%',
  height: '600px',
});

const emit = defineEmits<{
  select: [file: FluxFile];
  close: [];
  ready: [];
  event: [event: FluxEvent];
}>();

const handle = useFluxFiles({
  endpoint: props.endpoint,
  token: props.token,
  disk: props.disk,
  mode: props.mode,
  allowedTypes: props.allowedTypes,
  maxSize: props.maxSize,
  locale: props.locale,
  onSelect: (file) => emit('select', file),
  onClose: () => emit('close'),
  onReady: () => emit('ready'),
  onEvent: (event) => emit('event', event),
});

const containerStyle = computed(() => ({
  width: typeof props.width === 'number' ? `${props.width}px` : props.width,
  height: typeof props.height === 'number' ? `${props.height}px` : props.height,
}));

defineExpose({
  command: handle.command,
  navigate: handle.navigate,
  setDisk: handle.setDisk,
  refresh: handle.refresh,
  search: handle.search,
  crossCopy: handle.crossCopy,
  crossMove: handle.crossMove,
  crop: handle.crop,
  aiTag: handle.aiTag,
  ready: handle.ready,
});
</script>

<template>
  <div :style="containerStyle">
    <iframe
      :ref="(el) => { handle.iframeRef.value = el as HTMLIFrameElement | null }"
      :src="handle.iframeSrc.value"
      style="width: 100%; height: 100%; border: none"
      allow="clipboard-write"
      title="FluxFiles File Manager"
    />
  </div>
</template>
