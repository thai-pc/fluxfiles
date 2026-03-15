<script setup lang="ts">
import { watch, onMounted, onUnmounted } from 'vue';
import { useFluxFiles } from './useFluxFiles';
import type { FluxFile, FluxEvent } from './types';

const props = withDefaults(defineProps<{
  open: boolean;
  endpoint: string;
  token: string;
  disk?: string;
  mode?: 'picker' | 'browser';
  multiple?: boolean;
  allowedTypes?: string[] | null;
  maxSize?: number | null;
  locale?: string | null;
  overlayClass?: string;
  modalClass?: string;
}>(), {
  disk: 'local',
  mode: 'picker',
  multiple: false,
});

const emit = defineEmits<{
  select: [file: FluxFile | FluxFile[]];
  close: [];
  ready: [];
  event: [event: FluxEvent];
  'update:open': [value: boolean];
}>();

const handle = useFluxFiles({
  endpoint: props.endpoint,
  token: props.token,
  disk: props.disk,
  mode: props.mode,
  multiple: props.multiple,
  allowedTypes: props.allowedTypes,
  maxSize: props.maxSize,
  locale: props.locale,
  onSelect: (file) => emit('select', file),
  onClose: () => {
    emit('close');
    emit('update:open', false);
  },
  onReady: () => emit('ready'),
  onEvent: (event) => emit('event', event),
});

function onKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    emit('close');
    emit('update:open', false);
  }
}

function onOverlayClick(e: MouseEvent) {
  if (e.target === e.currentTarget) {
    emit('close');
    emit('update:open', false);
  }
}

let prevOverflow = '';

watch(() => props.open, (val) => {
  if (val) {
    prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKeyDown);
  } else {
    document.body.style.overflow = prevOverflow;
    document.removeEventListener('keydown', onKeyDown);
  }
});

onUnmounted(() => {
  document.body.style.overflow = prevOverflow;
  document.removeEventListener('keydown', onKeyDown);
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      :class="overlayClass"
      :style="overlayClass ? undefined : {
        position: 'fixed',
        inset: '0',
        background: 'rgba(0, 0, 0, 0.5)',
        zIndex: 99999,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
      }"
      role="dialog"
      aria-modal="true"
      aria-label="FluxFiles File Manager"
      @click="onOverlayClick"
    >
      <div
        :class="modalClass"
        :style="modalClass ? undefined : {
          width: '90vw',
          maxWidth: '1200px',
          height: '85vh',
          background: '#fff',
          borderRadius: '8px',
          overflow: 'hidden',
          boxShadow: '0 25px 50px rgba(0, 0, 0, 0.25)',
        }"
      >
        <iframe
          :ref="(el) => { handle.iframeRef.value = el as HTMLIFrameElement | null }"
          :src="handle.iframeSrc.value"
          style="width: 100%; height: 100%; border: none"
          allow="clipboard-write"
          title="FluxFiles File Manager"
        />
      </div>
    </div>
  </Teleport>
</template>
