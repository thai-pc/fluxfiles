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
  });

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
        style={modalClassName ? undefined : defaultModalStyle}
      >
        <div style={headerStyle}>
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
