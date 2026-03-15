import type { CSSProperties } from 'vue';

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

export interface FluxEvent {
  action: string;
  disk?: string;
  path?: string;
  file?: FluxFile;
  [key: string]: unknown;
}

export interface FluxFilesConfig {
  endpoint: string;
  token: string;
  disk?: string;
  mode?: 'picker' | 'browser';
  allowedTypes?: string[] | null;
  maxSize?: number | null;
  locale?: string | null;
}

export interface FluxFilesProps extends FluxFilesConfig {
  width?: string | number;
  height?: string | number;
}

export interface FluxFilesModalProps extends FluxFilesConfig {
  open: boolean;
  overlayClass?: string;
  modalClass?: string;
}

export type FluxCommand =
  | { action: 'navigate'; path: string }
  | { action: 'setDisk'; disk: string }
  | { action: 'refresh' }
  | { action: 'search'; q: string }
  | { action: 'crossCopy'; dst_disk: string; dst_path?: string }
  | { action: 'crossMove'; dst_disk: string; dst_path?: string }
  | { action: 'crop'; x: number; y: number; width: number; height: number; save_path?: string }
  | { action: 'aiTag' };

export interface FluxFilesHandle {
  command: (action: string, data?: Record<string, unknown>) => void;
  navigate: (path: string) => void;
  setDisk: (disk: string) => void;
  refresh: () => void;
  search: (q: string) => void;
  crossCopy: (dstDisk: string, dstPath?: string) => void;
  crossMove: (dstDisk: string, dstPath?: string) => void;
  crop: (x: number, y: number, width: number, height: number, savePath?: string) => void;
  aiTag: () => void;
  ready: boolean;
}

export interface FluxMessage {
  source: 'fluxfiles';
  type: 'FM_READY' | 'FM_SELECT' | 'FM_EVENT' | 'FM_CLOSE' | 'FM_CONFIG' | 'FM_COMMAND';
  v: number;
  id: string;
  payload: Record<string, unknown>;
}
