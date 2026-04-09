import { ComputedRef } from 'vue';
import { NuxtApp } from '#app';
import { Ref } from 'vue';

declare const _default: (nuxtApp: NuxtApp) => void;
export default _default;

declare interface FluxEvent {
    action: string;
    disk?: string;
    path?: string;
    file?: FluxFile;
    [key: string]: unknown;
}

declare interface FluxFile {
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
    variant?: 'original' | 'thumb' | 'medium' | 'large';
}

declare interface FluxFilesConfig {
    endpoint: string;
    token: string;
    disk?: string;
    mode?: 'picker' | 'browser';
    /** When true, onSelect receives array of FluxFile */
    multiple?: boolean;
    allowedTypes?: string[] | null;
    maxSize?: number | null;
    locale?: string | null;
}

declare type TokenRefreshHandler = (context: {
    reason: string;
    disk?: string;
    path?: string;
}) => Promise<string | null>;

export declare function useFluxFiles(options: UseFluxFilesOptions | Ref<UseFluxFilesOptions>): {
    iframeRef: Ref<HTMLIFrameElement | null, HTMLIFrameElement | null>;
    iframeSrc: ComputedRef<string>;
    ready: Ref<boolean, boolean>;
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
};

declare interface UseFluxFilesOptions extends FluxFilesConfig {
    onSelect?: (file: FluxFile | FluxFile[]) => void;
    onClose?: () => void;
    onReady?: () => void;
    onEvent?: (event: FluxEvent) => void;
    onTokenRefresh?: TokenRefreshHandler;
}

export { }
