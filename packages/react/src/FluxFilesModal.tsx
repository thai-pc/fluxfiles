import React, { useCallback, useEffect } from 'react';
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

const defaultModalStyle: React.CSSProperties = {
  position: 'relative',
  width: '90vw',
  maxWidth: '1200px',
  height: '85vh',
  background: '#fff',
  borderRadius: '8px',
  overflow: 'hidden',
  boxShadow: '0 25px 50px rgba(0, 0, 0, 0.25)',
};

const closeButtonStyle: React.CSSProperties = {
  position: 'absolute',
  top: '8px',
  right: '8px',
  zIndex: 1,
  width: '30px',
  height: '30px',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  border: 'none',
  borderRadius: '50%',
  background: 'rgba(0, 0, 0, 0.55)',
  color: '#fff',
  fontSize: '18px',
  lineHeight: 1,
  cursor: 'pointer',
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
        <button
          type="button"
          onClick={() => onClose?.()}
          aria-label="Close"
          style={closeButtonStyle}
        >
          &times;
        </button>
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
  );
}
