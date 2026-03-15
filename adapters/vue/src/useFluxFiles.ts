import { ref, watch, onMounted, onUnmounted, computed, type Ref } from 'vue';
import type { FluxFile, FluxEvent, FluxFilesConfig, FluxMessage } from './types';

const SOURCE = 'fluxfiles';
const VERSION = 1;

function uid(): string {
  return 'ff-' + Math.random().toString(36).slice(2, 11) + Date.now().toString(36);
}

export interface UseFluxFilesOptions extends FluxFilesConfig {
  onSelect?: (file: FluxFile) => void;
  onClose?: () => void;
  onReady?: () => void;
  onEvent?: (event: FluxEvent) => void;
}

export function useFluxFiles(options: UseFluxFilesOptions | Ref<UseFluxFilesOptions>) {
  const iframeRef = ref<HTMLIFrameElement | null>(null);
  const ready = ref(false);

  const opts = computed(() => (options as any).value ?? options);

  const endpoint = computed(() => (opts.value.endpoint || '').replace(/\/+$/, ''));
  const iframeSrc = computed(() => endpoint.value + '/public/index.html');

  function post(type: string, payload: Record<string, unknown> = {}) {
    const el = iframeRef.value;
    if (!el?.contentWindow) return;
    el.contentWindow.postMessage(
      { source: SOURCE, type, v: VERSION, id: uid(), payload },
      '*'
    );
  }

  function sendConfig() {
    const o = opts.value;
    post('FM_CONFIG', {
      disk: o.disk || 'local',
      token: o.token || '',
      mode: o.mode || 'picker',
      allowedTypes: o.allowedTypes || null,
      maxSize: o.maxSize || null,
      endpoint: o.endpoint || '',
      locale: o.locale || null,
    });
  }

  function onMessage(e: MessageEvent) {
    const msg = e.data as FluxMessage;
    if (!msg || msg.source !== SOURCE) return;

    const o = opts.value;

    switch (msg.type) {
      case 'FM_READY':
        ready.value = true;
        sendConfig();
        o.onReady?.();
        break;
      case 'FM_SELECT':
        o.onSelect?.(msg.payload as unknown as FluxFile);
        break;
      case 'FM_EVENT':
        o.onEvent?.(msg.payload as unknown as FluxEvent);
        break;
      case 'FM_CLOSE':
        o.onClose?.();
        break;
    }
  }

  onMounted(() => {
    window.addEventListener('message', onMessage);
  });

  onUnmounted(() => {
    window.removeEventListener('message', onMessage);
  });

  watch(
    () => [opts.value.token, opts.value.disk, opts.value.mode, opts.value.locale],
    () => {
      if (ready.value) sendConfig();
    }
  );

  function command(action: string, data: Record<string, unknown> = {}) {
    post('FM_COMMAND', { action, ...data });
  }

  const navigate   = (path: string) => command('navigate', { path });
  const setDisk    = (disk: string) => command('setDisk', { disk });
  const refresh    = ()             => command('refresh');
  const search     = (q: string)    => command('search', { q });
  const crossCopy  = (dstDisk: string, dstPath?: string) => command('crossCopy', { dst_disk: dstDisk, dst_path: dstPath || '' });
  const crossMove  = (dstDisk: string, dstPath?: string) => command('crossMove', { dst_disk: dstDisk, dst_path: dstPath || '' });
  const crop       = (x: number, y: number, width: number, height: number, savePath?: string) => command('crop', { x, y, width, height, save_path: savePath || '' });
  const aiTag      = () => command('aiTag');

  return {
    iframeRef,
    iframeSrc,
    ready,
    command,
    navigate,
    setDisk,
    refresh,
    search,
    crossCopy,
    crossMove,
    crop,
    aiTag,
  };
}
