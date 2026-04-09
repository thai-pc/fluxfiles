import React$1 from 'react';
import * as react_jsx_runtime from 'react/jsx-runtime';

/** A file or directory entry returned by FluxFiles. */
interface FluxFile {
    path: string;
    basename: string;
    type: 'file' | 'dir';
    size?: number;
    mime?: string;
    modified?: number;
    url?: string;
    title?: string;
    alt_text?: string;
    caption?: string;
    hash?: string;
    variants?: Record<string, {
        url: string;
        key: string;
    }> | null;
    /** Set when a specific variant was selected via the variant picker. */
    variant?: 'original' | 'thumb' | 'medium' | 'large';
}
/** Event payload dispatched by the file manager iframe. */
interface FluxEvent {
    action: string;
    disk?: string;
    path?: string;
    file?: FluxFile;
    [key: string]: unknown;
}
/** Configuration for the FluxFiles component. */
interface FluxFilesConfig {
    /** Base URL of the FluxFiles API. */
    endpoint: string;
    /** JWT token for authentication. */
    token: string;
    /** Storage disk to use. */
    disk?: string;
    /** Display mode: "picker" selects a file, "browser" is free-browse. */
    mode?: 'picker' | 'browser';
    /** When true, onSelect receives array of FluxFile. */
    multiple?: boolean;
    /** Filter displayed file types (e.g. ["image/*", ".pdf"]). */
    allowedTypes?: string[] | null;
    /** Max file size filter in bytes. */
    maxSize?: number | null;
    /** Locale code (e.g. "en", "vi", "ar"). */
    locale?: string | null;
}
/**
 * Callback for automatic token refresh.
 * Called when the iframe receives a 401 and needs a new JWT.
 * Return the new token string, or null/throw to signal failure.
 */
type TokenRefreshHandler = (context: {
    reason: string;
    disk?: string;
    path?: string;
}) => Promise<string | null>;
/** Props for the <FluxFiles /> embedded component. */
interface FluxFilesProps extends FluxFilesConfig {
    /** Container width. */
    width?: string | number;
    /** Container height. */
    height?: string | number;
    /** CSS class for the wrapper div. */
    className?: string;
    /** Inline styles for the wrapper div. */
    style?: React.CSSProperties;
    /** Fired when a file is selected (picker mode). Receives array when multiple=true. */
    onSelect?: (file: FluxFile | FluxFile[]) => void;
    /** Fired when the file manager signals a close. */
    onClose?: () => void;
    /** Fired when the iframe is ready. */
    onReady?: () => void;
    /** Fired on file operations (upload, delete, move, etc.). */
    onEvent?: (event: FluxEvent) => void;
    /** Called when the iframe needs a fresh JWT (401 received). Return new token or null. */
    onTokenRefresh?: TokenRefreshHandler;
}
/** Props for the <FluxFilesModal /> component. */
interface FluxFilesModalProps extends FluxFilesConfig {
    /** Whether the modal is open. */
    open: boolean;
    /** Fired when a file is selected. Receives array when multiple=true. */
    onSelect?: (file: FluxFile | FluxFile[]) => void;
    /** Fired when the modal should close (overlay click, escape, or FM_CLOSE). */
    onClose?: () => void;
    /** Fired when the iframe is ready. */
    onReady?: () => void;
    /** Fired on file operations. */
    onEvent?: (event: FluxEvent) => void;
    /** Called when the iframe needs a fresh JWT (401 received). Return new token or null. */
    onTokenRefresh?: TokenRefreshHandler;
    /** CSS class for the overlay. */
    overlayClassName?: string;
    /** CSS class for the modal content. */
    modalClassName?: string;
}
/** Commands that can be sent to the file manager. */
type FluxCommand = {
    action: 'navigate';
    path: string;
} | {
    action: 'setDisk';
    disk: string;
} | {
    action: 'refresh';
} | {
    action: 'search';
    q: string;
} | {
    action: 'crossCopy';
    dst_disk: string;
    dst_path?: string;
} | {
    action: 'crossMove';
    dst_disk: string;
    dst_path?: string;
} | {
    action: 'crop';
    x: number;
    y: number;
    width: number;
    height: number;
    save_path?: string;
} | {
    action: 'aiTag';
} | {
    action: 'setLocale';
    locale: string;
};
/** Return type of useFluxFiles hook. */
interface FluxFilesHandle {
    /** Send a command to the iframe. */
    command: (action: string, data?: Record<string, unknown>) => void;
    /** Navigate to a path. */
    navigate: (path: string) => void;
    /** Switch disk. */
    setDisk: (disk: string) => void;
    /** Refresh the file list. */
    refresh: () => void;
    /** Search files. */
    search: (q: string) => void;
    /** Copy selected files to another disk. */
    crossCopy: (dstDisk: string, dstPath?: string) => void;
    /** Move selected files to another disk. */
    crossMove: (dstDisk: string, dstPath?: string) => void;
    /** Crop the currently selected image. */
    crop: (x: number, y: number, width: number, height: number, savePath?: string) => void;
    /** Trigger AI tagging on the currently selected image. */
    aiTag: () => void;
    /** Switch locale/language at runtime. */
    setLocale: (locale: string) => void;
    /** Push a new token to the iframe (e.g. after background refresh). */
    updateToken: (token: string) => void;
    /** Whether the iframe has reported ready. */
    ready: boolean;
}

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
declare const FluxFiles: React$1.ForwardRefExoticComponent<FluxFilesProps & React$1.RefAttributes<FluxFilesHandle>>;

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
declare function FluxFilesModal({ open, endpoint, token, disk, mode, multiple, allowedTypes, maxSize, onSelect, onClose, onReady, onEvent, onTokenRefresh, overlayClassName, modalClassName, }: FluxFilesModalProps): react_jsx_runtime.JSX.Element | null;

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
declare function useFluxFiles(options: UseFluxFilesOptions): FluxFilesHandle & {
    /** Ref callback — attach to the <iframe> element. */
    iframeRef: (el: HTMLIFrameElement | null) => void;
    /** The iframe src URL. */
    iframeSrc: string;
};

export { type FluxCommand, type FluxEvent, type FluxFile, FluxFiles, type FluxFilesConfig, type FluxFilesHandle, FluxFilesModal, type FluxFilesModalProps, type FluxFilesProps, type TokenRefreshHandler, useFluxFiles };
