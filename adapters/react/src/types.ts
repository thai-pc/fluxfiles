/** A file or directory entry returned by FluxFiles. */
export interface FluxFile {
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
  variants?: Record<string, string>;
}

/** Event payload dispatched by the file manager iframe. */
export interface FluxEvent {
  action: string;
  disk?: string;
  path?: string;
  file?: FluxFile;
  [key: string]: unknown;
}

/** Configuration for the FluxFiles component. */
export interface FluxFilesConfig {
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

/** Props for the <FluxFiles /> embedded component. */
export interface FluxFilesProps extends FluxFilesConfig {
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
}

/** Props for the <FluxFilesModal /> component. */
export interface FluxFilesModalProps extends FluxFilesConfig {
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
  /** CSS class for the overlay. */
  overlayClassName?: string;
  /** CSS class for the modal content. */
  modalClassName?: string;
}

/** Commands that can be sent to the file manager. */
export type FluxCommand =
  | { action: 'navigate'; path: string }
  | { action: 'setDisk'; disk: string }
  | { action: 'refresh' }
  | { action: 'search'; q: string }
  | { action: 'crossCopy'; dst_disk: string; dst_path?: string }
  | { action: 'crossMove'; dst_disk: string; dst_path?: string }
  | { action: 'crop'; x: number; y: number; width: number; height: number; save_path?: string }
  | { action: 'aiTag' };

/** Return type of useFluxFiles hook. */
export interface FluxFilesHandle {
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
  /** Whether the iframe has reported ready. */
  ready: boolean;
}

/** Internal postMessage protocol types. */
export interface FluxMessage {
  source: 'fluxfiles';
  type: 'FM_READY' | 'FM_SELECT' | 'FM_EVENT' | 'FM_CLOSE' | 'FM_CONFIG' | 'FM_COMMAND';
  v: number;
  id: string;
  payload: Record<string, unknown>;
}
