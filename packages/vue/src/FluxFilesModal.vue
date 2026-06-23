<script setup lang="ts">
import { watch, onMounted, onUnmounted } from 'vue';
import { useFluxFiles } from './useFluxFiles';
import { IFRAME_ALLOW } from './iframe';
import type { FluxFile, FluxEvent } from './types';

const props = withDefaults(defineProps<{
  open: boolean;
  endpoint: string;
  token: string;
  disk?: string;
  disks?: string[];
  path?: string;
  theme?: 'light' | 'dark' | 'auto';
  mode?: 'picker' | 'browser';
  multiple?: boolean;
  allowedTypes?: string[] | null;
  maxUploadMb?: number | null;
  maxSize?: number | null;
  maxFiles?: number | null;
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
  disks: props.disks,
  path: props.path,
  theme: props.theme,
  mode: props.mode,
  multiple: props.multiple,
  allowedTypes: props.allowedTypes,
  maxUploadMb: props.maxUploadMb,
  maxSize: props.maxSize,
  maxFiles: props.maxFiles,
  locale: props.locale,
  onSelect: (file) => emit('select', file),
  onClose: () => {
    emit('close');
    emit('update:open', false);
  },
  onReady: () => emit('ready'),
  onEvent: (event) => emit('event', event),
});

function close() {
  emit('close');
  emit('update:open', false);
}

function onKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape') close();
}

function onOverlayClick(e: MouseEvent) {
  if (e.target === e.currentTarget) close();
}

let prevOverflow = '';

function lockBody() {
  if (typeof document === 'undefined') return;
  prevOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  document.addEventListener('keydown', onKeyDown);
}
function unlockBody() {
  if (typeof document === 'undefined') return;
  document.body.style.overflow = prevOverflow;
  document.removeEventListener('keydown', onKeyDown);
}

watch(() => props.open, (val) => (val ? lockBody() : unlockBody()));

// A modal mounted already-open must lock scroll + bind Escape (the watch above
// only fires on a CHANGE). Client-only via onMounted, so it's SSR/Nuxt-safe.
onMounted(() => {
  if (props.open) lockBody();
});

onUnmounted(() => unlockBody());
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
          position: 'relative',
          width: '90vw',
          maxWidth: '1200px',
          height: '85vh',
          background: '#fff',
          borderRadius: '8px',
          overflow: 'hidden',
          boxShadow: '0 25px 50px rgba(0, 0, 0, 0.25)',
        }"
      >
        <button
          type="button"
          aria-label="Close"
          :style="{
            position: 'absolute', top: '8px', right: '8px', zIndex: 1,
            width: '30px', height: '30px', display: 'flex', alignItems: 'center',
            justifyContent: 'center', border: 'none', borderRadius: '50%',
            background: 'rgba(0, 0, 0, 0.55)', color: '#fff', fontSize: '18px',
            lineHeight: 1, cursor: 'pointer',
          }"
          @click="close"
        >&times;</button>
        <iframe
          :ref="(el) => { handle.iframeRef.value = el as HTMLIFrameElement | null }"
          :src="handle.iframeSrc.value"
          style="width: 100%; height: 100%; border: none"
          :allow="IFRAME_ALLOW"
          allowfullscreen
          title="FluxFiles File Manager"
        />
      </div>
    </div>
  </Teleport>
</template>
