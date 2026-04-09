import { ComponentOptionsMixin } from 'vue';
import { ComponentProvideOptions } from 'vue';
import { ComputedRef } from 'vue';
import { DefineComponent } from 'vue';
import { PublicProps } from 'vue';
import { Ref } from 'vue';

declare type __VLS_Props = {
    endpoint: string;
    token: string;
    disk?: string;
    mode?: 'picker' | 'browser';
    multiple?: boolean;
    allowedTypes?: string[] | null;
    maxSize?: number | null;
    locale?: string | null;
    width?: string | number;
    height?: string | number;
};

declare type __VLS_Props_2 = {
    open: boolean;
    endpoint: string;
    token: string;
    disk?: string;
    mode?: 'picker' | 'browser';
    multiple?: boolean;
    allowedTypes?: string[] | null;
    maxSize?: number | null;
    locale?: string | null;
    overlayClass?: string;
    modalClass?: string;
};

export declare type FluxCommand = {
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

export declare interface FluxEvent {
    action: string;
    disk?: string;
    path?: string;
    file?: FluxFile;
    [key: string]: unknown;
}

export declare interface FluxFile {
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

export declare const FluxFiles: DefineComponent<__VLS_Props, {
command: (action: string, data?: Record<string, unknown>) => void;
navigate: (path: string) => void;
setDisk: (disk: string) => void;
refresh: () => void;
search: (q: string) => void;
crossCopy: (dstDisk: string, dstPath?: string) => void;
crossMove: (dstDisk: string, dstPath?: string) => void;
crop: (x: number, y: number, width: number, height: number, savePath?: string) => void;
aiTag: () => void;
ready: Ref<boolean, boolean>;
}, {}, {}, {}, ComponentOptionsMixin, ComponentOptionsMixin, {
close: () => any;
select: (file: FluxFile | FluxFile[]) => any;
ready: () => any;
event: (event: FluxEvent) => any;
}, string, PublicProps, Readonly<__VLS_Props> & Readonly<{
onClose?: (() => any) | undefined;
onSelect?: ((file: FluxFile | FluxFile[]) => any) | undefined;
onReady?: (() => any) | undefined;
onEvent?: ((event: FluxEvent) => any) | undefined;
}>, {
disk: string;
mode: "picker" | "browser";
multiple: boolean;
width: string | number;
height: string | number;
}, {}, {}, {}, string, ComponentProvideOptions, false, {}, HTMLDivElement>;

export declare interface FluxFilesConfig {
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

export declare interface FluxFilesHandle {
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

export declare const FluxFilesModal: DefineComponent<__VLS_Props_2, {}, {}, {}, {}, ComponentOptionsMixin, ComponentOptionsMixin, {
close: () => any;
select: (file: FluxFile | FluxFile[]) => any;
ready: () => any;
event: (event: FluxEvent) => any;
"update:open": (value: boolean) => any;
}, string, PublicProps, Readonly<__VLS_Props_2> & Readonly<{
onClose?: (() => any) | undefined;
onSelect?: ((file: FluxFile | FluxFile[]) => any) | undefined;
onReady?: (() => any) | undefined;
onEvent?: ((event: FluxEvent) => any) | undefined;
"onUpdate:open"?: ((value: boolean) => any) | undefined;
}>, {
disk: string;
mode: "picker" | "browser";
multiple: boolean;
}, {}, {}, {}, string, ComponentProvideOptions, false, {}, any>;

export declare interface FluxFilesModalProps extends FluxFilesConfig {
    open: boolean;
    overlayClass?: string;
    modalClass?: string;
}

export declare interface FluxFilesProps extends FluxFilesConfig {
    width?: string | number;
    height?: string | number;
}

export declare type TokenRefreshHandler = (context: {
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

export declare interface UseFluxFilesOptions extends FluxFilesConfig {
    onSelect?: (file: FluxFile | FluxFile[]) => void;
    onClose?: () => void;
    onReady?: () => void;
    onEvent?: (event: FluxEvent) => void;
    onTokenRefresh?: TokenRefreshHandler;
}

export { }
