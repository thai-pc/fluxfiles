import type { FluxFile } from 'fluxfiles';

type MaybePromise<T> = T | Promise<T>;

/** `config.fluxfiles`. Option names mirror the CKEditor 4 plugin. */
export interface FluxFilesConfig {
  /** FluxFiles server URL. */
  endpoint: string;
  /** JWT, or a function returning one (called each time the picker opens). */
  token: string | (() => MaybePromise<string>);
  /** Storage disk. Default `'local'`. */
  disk?: string;
  /** Disks shown in the picker sidebar. */
  disks?: string[];
  /** Folder to open the picker in. */
  path?: string;
  /** UI language code. */
  locale?: string;
  /** `light` | `dark` | `auto`. */
  theme?: string;
  /** Allow multi-file selection; every selected file is inserted. Default `false`. */
  multiple?: boolean;
  /** Restrict selectable types (e.g. `['image']`). */
  allowedTypes?: string[];
  /** Max size per uploaded file, MB. */
  maxUploadMb?: number;
  /** Max files per upload batch. */
  maxFiles?: number;
  /** Called when the picker needs a fresh JWT (401). Return a token or null. */
  onTokenRefresh?: (context: { reason: string; disk?: string; path?: string }) => Promise<string | null>;
  /** Offer FluxFiles in the image toolbar's insert dropdown. Default `true`. */
  imageInsert?: boolean;
  /** Called after the selection has been inserted. */
  onInsert?: (files: FluxFile[]) => void;
}

declare module '@ckeditor/ckeditor5-core' {
  interface EditorConfig {
    fluxfiles?: FluxFilesConfig;
  }
}
