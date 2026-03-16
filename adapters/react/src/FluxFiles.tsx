import React, { forwardRef, useImperativeHandle } from 'react';
import type { FluxFilesProps, FluxFilesHandle } from './types';
import { useFluxFiles } from './useFluxFiles';

/**
 * Embedded FluxFiles file manager component.
 *
 * Renders an iframe inside a container div. Use `ref` to access command methods.
 *
 * @example
 * ```tsx
 * const ref = useRef<FluxFilesHandle>(null);
 *
 * <FluxFiles
 *   ref={ref}
 *   endpoint="https://files.example.com"
 *   token={jwt}
 *   disk="local"
 *   onSelect={(file) => console.log(file)}
 *   height="600px"
 * />
 *
 * // Programmatic control:
 * ref.current?.navigate('/uploads');
 * ref.current?.refresh();
 * ```
 */
export const FluxFiles = forwardRef<FluxFilesHandle, FluxFilesProps>(
  function FluxFiles(props, ref) {
    const {
      endpoint,
      token,
      disk,
      mode,
      multiple,
      allowedTypes,
      maxSize,
      width = '100%',
      height = '600px',
      className,
      style,
      onSelect,
      onClose,
      onReady,
      onEvent,
    } = props;

    const handle = useFluxFiles({
      endpoint,
      token,
      disk,
      mode,
      multiple,
      allowedTypes,
      maxSize,
      onSelect,
      onClose,
      onReady,
      onEvent,
    });

    useImperativeHandle(ref, () => ({
      command: handle.command,
      navigate: handle.navigate,
      setDisk: handle.setDisk,
      refresh: handle.refresh,
      search: handle.search,
      crossCopy: handle.crossCopy,
      crossMove: handle.crossMove,
      crop: handle.crop,
      aiTag: handle.aiTag,
      setLocale: handle.setLocale,
      ready: handle.ready,
    }), [handle]);

    const containerStyle: React.CSSProperties = {
      width: typeof width === 'number' ? `${width}px` : width,
      height: typeof height === 'number' ? `${height}px` : height,
      ...style,
    };

    return (
      <div className={className} style={containerStyle}>
        <iframe
          ref={handle.iframeRef}
          src={handle.iframeSrc}
          style={{ width: '100%', height: '100%', border: 'none' }}
          allow="clipboard-write"
          title="FluxFiles File Manager"
        />
      </div>
    );
  }
);
