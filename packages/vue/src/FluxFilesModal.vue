<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
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

// A `computed` IS a Ref, so useFluxFiles' `(options as any).value ?? options`
// unwrap tracks every prop below — a plain object literal here would freeze
// useFluxFiles' internal computed/watch sources at their initial setup() values,
// which most notably broke the FM_CONFIG resend on a `theme` prop change (the
// chrome would recolor via FM_THEME below, but the iframe body never followed).
const options = computed(() => ({
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
  onSelect: (file: FluxFile | FluxFile[]) => emit('select', file),
  onClose: () => {
    emit('close');
    emit('update:open', false);
  },
  onReady: () => emit('ready'),
  onEvent: (event: FluxEvent) => emit('event', event),
  // Runtime theme sync: the embedded UI toggled/resolved its theme — darken the chrome too.
  onThemeChange: (t: 'dark' | 'light' | undefined) => { chromeDark.value = t === 'dark'; },
}));

const handle = useFluxFiles(options);

function close() {
  emit('close');
  emit('update:open', false);
}

// macOS traffic-light: the red dot reveals a faint × on hover/focus.
const closeHover = ref(false);

// Theme the modal CHROME (window + header) so dark mode darkens it too, not just
// the embedded iframe. The chrome lives in the HOST origin and can't read the
// iframe's resolved theme, so when `theme` is auto/unset we mirror the embedded UI's
// anti-flash boot: prefer the last persisted theme (same localStorage key), then the
// OS preference — this stops the header flashing light before a dark theme settles.
const THEME_KEY = 'fluxfiles_theme';
function resolveChromeDark(theme?: 'light' | 'dark' | 'auto'): boolean {
  if (theme === 'dark') return true;
  if (theme === 'light') return false;
  try {
    const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(THEME_KEY) : null;
    if (stored === 'dark') return true;
    if (stored === 'light') return false;
  } catch {
    /* blocked storage — fall through */
  }
  return typeof window !== 'undefined' && !!window.matchMedia &&
    window.matchMedia('(prefers-color-scheme: dark)').matches;
}
// Stateful: starts from the anti-flash boot resolution, then tracks the embedded UI's
// runtime theme via FM_THEME (onThemeChange above) and any host-driven `theme` prop change.
const chromeDark = ref(resolveChromeDark(props.theme));
watch(() => props.theme, () => { chromeDark.value = resolveChromeDark(props.theme); });
const chromeBg = computed(() => (chromeDark.value ? '#2b2b2e' : '#f5f5f7'));
const chromeBorder = computed(() => (chromeDark.value ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)'));
// Persist an explicit theme so the next mount boots the chrome from it (anti-flash).
watch(() => props.theme, (t) => {
  if (t !== 'dark' && t !== 'light') return;
  try { localStorage.setItem(THEME_KEY, t); } catch { /* ignore */ }
}, { immediate: true });

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
      <!-- macOS-style window matching the browser SDK overlay (Laravel/WordPress),
           so the Close affordance is identical across every adapter. -->
      <div
        :class="modalClass"
        :style="modalClass ? undefined : {
          width: '90vw',
          maxWidth: '1200px',
          height: '85vh',
          background: chromeBg,
          borderRadius: '10px',
          overflow: 'hidden',
          boxShadow: '0 25px 50px rgba(0, 0, 0, 0.22)',
          display: 'flex',
          flexDirection: 'column',
        }"
      >
        <div :style="{
          flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'flex-start',
          padding: '10px 12px', borderBottom: '1px solid ' + chromeBorder, background: chromeBg,
        }">
          <button
            type="button"
            aria-label="Close"
            :style="{
              width: '13px', height: '13px', padding: 0, border: 'none', borderRadius: '50%',
              background: '#ff5f57', boxShadow: 'inset 0 0 0 0.5px rgba(0, 0, 0, 0.14)',
              cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center',
              fontSize: '11px', lineHeight: 1, fontWeight: 700, flexShrink: 0,
              transition: 'filter .15s, color .15s',
              color: closeHover ? 'rgba(0, 0, 0, 0.55)' : 'rgba(0, 0, 0, 0)',
              filter: closeHover ? 'brightness(0.93)' : 'none',
            }"
            @click="close"
            @mouseenter="closeHover = true"
            @mouseleave="closeHover = false"
            @focus="closeHover = true"
            @blur="closeHover = false"
          >&times;</button>
        </div>
        <div :style="{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }">
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
    </div>
  </Teleport>
</template>
