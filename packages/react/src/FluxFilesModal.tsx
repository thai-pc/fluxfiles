import React, { useCallback, useEffect, useState } from 'react';
import type { FluxFilesModalProps } from './types';
import { useFluxFiles } from './useFluxFiles';
import { IFRAME_ALLOW } from './iframe';

const defaultOverlayStyle: React.CSSProperties = {
  position: 'fixed',
  inset: 0,
  background: 'rgba(0, 0, 0, 0.5)',
  zIndex: 99999,
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
};

// Matches the browser SDK overlay (Laravel/WordPress) so the close affordance is
// identical across every adapter: a macOS-style window with a grey header bar.
const defaultModalStyle: React.CSSProperties = {
  width: '90vw',
  maxWidth: '1200px',
  height: '85vh',
  background: '#f5f5f7',
  borderRadius: '10px',
  overflow: 'hidden',
  boxShadow: '0 25px 50px rgba(0, 0, 0, 0.22)',
  display: 'flex',
  flexDirection: 'column',
};

const headerStyle: React.CSSProperties = {
  flexShrink: 0,
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'flex-start',
  padding: '10px 12px',
  borderBottom: '1px solid rgba(0, 0, 0, 0.06)',
  background: '#f5f5f7',
};

// macOS traffic-light: a red dot that reveals a faint × on hover/focus.
const closeButtonStyle: React.CSSProperties = {
  width: '13px',
  height: '13px',
  padding: 0,
  border: 'none',
  borderRadius: '50%',
  background: '#ff5f57',
  boxShadow: 'inset 0 0 0 0.5px rgba(0, 0, 0, 0.14)',
  cursor: 'pointer',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  fontSize: '11px',
  lineHeight: 1,
  fontWeight: 700,
  flexShrink: 0,
  transition: 'filter .15s, color .15s',
};

// localStorage key the embedded UI uses for its anti-flash theme boot. The modal
// chrome lives in the HOST origin (separate from the iframe), so it can't read the
// iframe's resolved theme — but mirroring the same boot trick keeps the chrome from
// flashing light before a dark `theme` prop settles (e.g. resolved from a host
// effect/context a render late, while the iframe is already dark from its own boot).
const THEME_KEY = 'fluxfiles_theme';

/** Resolve whether the chrome should be dark, without flashing light first. */
function resolveChromeDark(theme?: 'light' | 'dark' | 'auto'): boolean {
  if (theme === 'dark') return true;
  if (theme === 'light') return false;
  // auto/unset: prefer the last persisted theme (same key the iframe boots from),
  // then the OS preference — so a repeat open boots in the right theme immediately.
  try {
    const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(THEME_KEY) : null;
    if (stored === 'dark') return true;
    if (stored === 'light') return false;
  } catch {
    /* private mode / blocked storage — fall through to the OS preference */
  }
  return (
    typeof window !== 'undefined' &&
    !!window.matchMedia &&
    window.matchMedia('(prefers-color-scheme: dark)').matches
  );
}

/**
 * Modal wrapper for FluxFiles.
 *
 * Renders a fullscreen overlay with the file manager when `open` is true.
 *
 * @example
 * ```tsx
 * const [open, setOpen] = useState(false);
 *
 * <button onClick={() => setOpen(true)}>Pick file</button>
 *
 * <FluxFilesModal
 *   open={open}
 *   endpoint="https://files.example.com"
 *   token={jwt}
 *   onSelect={(file) => {
 *     console.log(file);
 *     setOpen(false);
 *   }}
 *   onClose={() => setOpen(false)}
 * />
 * ```
 */
export function FluxFilesModal({
  open,
  endpoint,
  token,
  disk,
  disks,
  path,
  theme,
  locale,
  mode = 'picker',
  multiple = false,
  allowedTypes,
  maxUploadMb,
  maxSize,
  maxFiles,
  onSelect,
  onClose,
  onReady,
  onEvent,
  onTokenRefresh,
  overlayClassName,
  modalClassName,
}: FluxFilesModalProps) {
  // Chrome dark-ness is stateful: it starts from the anti-flash boot resolution, then
  // tracks the embedded UI's runtime theme via FM_THEME (onThemeChange below) so toggling
  // dark mode inside the iframe also darkens the surrounding window/header.
  const [dark, setDark] = useState(() => resolveChromeDark(theme));

  const handle = useFluxFiles({
    endpoint,
    token,
    disk,
    disks,
    path,
    theme,
    locale,
    mode,
    multiple,
    allowedTypes,
    maxUploadMb,
    maxSize,
    maxFiles,
    onSelect,
    onClose,
    onReady,
    onEvent,
    onTokenRefresh,
    onThemeChange: (t) => setDark(t === 'dark'),
  });

  // Re-resolve when the `theme` prop changes (host-driven override).
  useEffect(() => {
    setDark(resolveChromeDark(theme));
  }, [theme]);

  // Persist an explicit theme so the next mount boots the chrome from it (anti-flash),
  // matching the embedded UI's localStorage boot.
  useEffect(() => {
    if (theme !== 'dark' && theme !== 'light') return;
    try {
      localStorage.setItem(THEME_KEY, theme);
    } catch {
      /* ignore blocked storage */
    }
  }, [theme]);

  // Close on escape
  useEffect(() => {
    if (!open) return;

    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        onClose?.();
      }
    }

    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open, onClose]);

  // Prevent body scroll when open
  useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = prev;
    };
  }, [open]);

  const handleOverlayClick = useCallback(
    (e: React.MouseEvent) => {
      if (e.target === e.currentTarget) {
        onClose?.();
      }
    },
    [onClose]
  );

  const [closeHover, setCloseHover] = useState(false);

  if (!open) return null;

  // Theme the modal CHROME (window + header) to match the embedded UI's theme —
  // otherwise dark mode only darkens the iframe and the grey header stays light.
  // `dark` is the stateful value declared above (boot resolution + runtime FM_THEME).
  const chromeBg = dark ? '#2b2b2e' : '#f5f5f7';
  const chromeBorder = dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';

  return (
    <div
      className={overlayClassName}
      style={overlayClassName ? undefined : defaultOverlayStyle}
      onClick={handleOverlayClick}
      role="dialog"
      aria-modal="true"
      aria-label="FluxFiles File Manager"
    >
      <div
        className={modalClassName}
        style={modalClassName ? undefined : { ...defaultModalStyle, background: chromeBg }}
      >
        <div style={{ ...headerStyle, background: chromeBg, borderBottom: '1px solid ' + chromeBorder }}>
          <button
            type="button"
            onClick={() => onClose?.()}
            onMouseEnter={() => setCloseHover(true)}
            onMouseLeave={() => setCloseHover(false)}
            onFocus={() => setCloseHover(true)}
            onBlur={() => setCloseHover(false)}
            aria-label="Close"
            style={{
              ...closeButtonStyle,
              color: closeHover ? 'rgba(0, 0, 0, 0.55)' : 'rgba(0, 0, 0, 0)',
              filter: closeHover ? 'brightness(0.93)' : 'none',
            }}
          >
            &times;
          </button>
        </div>
        <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
          <iframe
            ref={handle.iframeRef}
            src={handle.iframeSrc}
            style={{ width: '100%', height: '100%', border: 'none' }}
            allow={IFRAME_ALLOW}
            allowFullScreen
            title="FluxFiles File Manager"
          />
        </div>
      </div>
    </div>
  );
}
