import type { CSSProperties } from 'vue';

export interface FluxFile {
  path: string;
  basename: string;
  key?: string;
  name?: string;
  type: 'file' | 'dir';
  is_dir?: boolean;
  disk?: string;
  size?: number;
  mime?: string;
  /** Image dimensions (present for images uploaded through FluxFiles). */
  width?: number;
  height?: number;
  /** Unix seconds. `created` is a stable upload/mkdir time; `modified` is the
   *  storage mtime (may be absent for S3/R2 dir prefixes). UI sorts by
   *  `created || modified`. Present on both file and folder entries. */
  created?: number;
  modified?: number;
  url?: string;
  /** Stable, non-expiring URL for embedding (public disk / public_url). */
  permanent_url?: string;
  title?: string;
  alt_text?: string;
  caption?: string;
  hash?: string;
  meta?: Record<string, unknown> | null;
  variants?: Record<string, { url: string; key: string }> | null;
  variant?: 'original' | 'thumb' | 'medium' | 'large';
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
  /** When true, onSelect receives array of FluxFile */
  multiple?: boolean;
  allowedTypes?: string[] | null;
  /** Max size per uploaded file, in **megabytes (MB)**. Preferred over `maxSize`. */
  maxUploadMb?: number | null;
  /** @deprecated Use `maxUploadMb` (MB). Bytes; converted when `maxUploadMb` is unset. */
  maxSize?: number | null;
  /** Max number of files per upload batch (0/undefined = unlimited). Server enforces the prefix total via the `max_files` claim. */
  maxFiles?: number | null;
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
  | { action: 'aiTag' }
  | { action: 'setLocale'; locale: string };

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
  setLocale: (locale: string) => void;
  updateToken: (token: string) => void;
  ready: boolean;
}

export type TokenRefreshHandler = (context: { reason: string; disk?: string; path?: string }) => Promise<string | null>;

export interface FluxMessage {
  source: 'fluxfiles';
  type: 'FM_READY' | 'FM_SELECT' | 'FM_EVENT' | 'FM_CLOSE' | 'FM_CONFIG' | 'FM_COMMAND' | 'FM_TOKEN_REFRESH';
  v: number;
  id: string;
  payload: Record<string, unknown>;
}
