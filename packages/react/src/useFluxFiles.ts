import { useCallback, useEffect, useRef, useState } from 'react';
import type { FluxFile, FluxEvent, FluxFilesConfig, FluxFilesHandle, FluxMessage, TokenRefreshHandler } from './types';

const SOURCE = 'fluxfiles';
const VERSION = 1;

function uid(): string {
  return 'ff-' + Math.random().toString(36).slice(2, 11) + Date.now().toString(36);
}

interface UseFluxFilesOptions extends FluxFilesConfig {
  onSelect?: (file: FluxFile | FluxFile[]) => void;
  onClose?: () => void;
  onReady?: () => void;
  onEvent?: (event: FluxEvent) => void;
  onTokenRefresh?: TokenRefreshHandler;
}

/**
 * Low-level hook that manages the postMessage bridge to a FluxFiles iframe.
 *
 * Returns a ref callback to attach to the iframe element, plus command helpers.
 */
export function useFluxFiles(options: UseFluxFilesOptions): FluxFilesHandle & {
  /** Ref callback — attach to the <iframe> element. */
  iframeRef: (el: HTMLIFrameElement | null) => void;
  /** The iframe src URL. */
  iframeSrc: string;
} {
  const iframeElRef = useRef<HTMLIFrameElement | null>(null);
  const [ready, setReady] = useState(false);
  const optionsRef = useRef(options);
  optionsRef.current = options;

  const endpoint = (options.endpoint || '').replace(/\/+$/, '');
  const iframeSrc = endpoint + '/public/index.html';

  // Post a message to the iframe
  const post = useCallback((type: string, payload: Record<string, unknown> = {}) => {
    const el = iframeElRef.current;
    if (!el?.contentWindow) return;
    el.contentWindow.postMessage(
      { source: SOURCE, type, v: VERSION, id: uid(), payload },
      '*'
    );
  }, []);

  // Send config when ready
  const sendConfig = useCallback(() => {
    const opts = optionsRef.current;
    post('FM_CONFIG', {
      disk: opts.disk || 'local',
      token: opts.token || '',
      mode: opts.mode || 'picker',
      multiple: !!opts.multiple,
      allowedTypes: opts.allowedTypes || null,
      maxSize: opts.maxSize || null,
      endpoint: opts.endpoint || '',
      locale: opts.locale || null,
    });
  }, [post]);

  // Listen for messages from iframe
  useEffect(() => {
    function onMessage(e: MessageEvent) {
      const msg = e.data as FluxMessage;
      if (!msg || msg.source !== SOURCE) return;

      const opts = optionsRef.current;

      switch (msg.type) {
        case 'FM_READY':
          setReady(true);
          sendConfig();
          opts.onReady?.();
          break;
        case 'FM_SELECT':
          opts.onSelect?.(msg.payload as unknown as FluxFile | FluxFile[]);
          break;
        case 'FM_EVENT':
          opts.onEvent?.(msg.payload as unknown as FluxEvent);
          break;
        case 'FM_TOKEN_REFRESH':
          if (opts.onTokenRefresh) {
            const payload = msg.payload as { reason: string; disk?: string; path?: string };
            Promise.resolve(opts.onTokenRefresh(payload))
              .then((newToken) => {
                if (newToken) {
                  post('FM_TOKEN_UPDATED', { token: newToken });
                } else {
                  post('FM_TOKEN_FAILED', { reason: 'refresh_returned_null' });
                }
              })
              .catch((err) => {
                post('FM_TOKEN_FAILED', { reason: (err as Error).message || 'refresh_error' });
              });
          } else {
            post('FM_TOKEN_FAILED', { reason: 'no_handler' });
          }
          break;
        case 'FM_CLOSE':
          opts.onClose?.();
          break;
      }
    }

    window.addEventListener('message', onMessage);
    return () => {
      window.removeEventListener('message', onMessage);
    };
  }, [sendConfig]);

  // Re-send config when token or disk changes
  useEffect(() => {
    if (ready) {
      sendConfig();
    }
  }, [options.token, options.disk, options.mode, options.multiple, options.locale, ready, sendConfig]);

  // Command helpers
  const command = useCallback(
    (action: string, data: Record<string, unknown> = {}) => {
      post('FM_COMMAND', { action, ...data });
    },
    [post]
  );

  const navigate = useCallback((path: string) => command('navigate', { path }), [command]);
  const setDisk = useCallback((disk: string) => command('setDisk', { disk }), [command]);
  const refresh = useCallback(() => command('refresh'), [command]);
  const search = useCallback((q: string) => command('search', { q }), [command]);
  const crossCopy = useCallback((dstDisk: string, dstPath?: string) => command('crossCopy', { dst_disk: dstDisk, dst_path: dstPath || '' }), [command]);
  const crossMove = useCallback((dstDisk: string, dstPath?: string) => command('crossMove', { dst_disk: dstDisk, dst_path: dstPath || '' }), [command]);
  const crop = useCallback((x: number, y: number, width: number, height: number, savePath?: string) => command('crop', { x, y, width, height, save_path: savePath || '' }), [command]);
  const aiTag = useCallback(() => command('aiTag'), [command]);
  const setLocale = useCallback((locale: string) => command('setLocale', { locale }), [command]);
  const updateToken = useCallback((token: string) => post('FM_TOKEN_UPDATED', { token }), [post]);

  const iframeRef = useCallback((el: HTMLIFrameElement | null) => {
    iframeElRef.current = el;
    if (!el) {
      setReady(false);
    }
  }, []);

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
    setLocale,
    updateToken,
  };
}
