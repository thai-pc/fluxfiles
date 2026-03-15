interface FluxFilesOpenOptions {
    endpoint: string;
    token: string;
    disk?: string;
    mode?: 'picker' | 'browser';
    /** Allow multi-select; when true, onSelect receives array of FluxFile */
    multiple?: boolean;
    locale?: string;
    allowedTypes?: string[];
    maxSize?: number;
    container?: string;
    onSelect?: (file: FluxFile | FluxFile[]) => void;
    onClose?: () => void;
}

interface FluxFile {
    path: string;
    basename: string;
    key?: string;
    name?: string;
    url?: string;
    size?: number;
    mime?: string;
    disk?: string;
    is_dir?: boolean;
    modified?: string;
    variants?: Record<string, string>;
}

interface FluxEvent {
    action: 'upload' | 'delete' | 'move' | 'copy' | 'mkdir' | 'restore' | 'purge' | 'trash' | 'crop' | 'ai_tag';
    disk: string;
    path: string;
    [key: string]: unknown;
}

type FluxFilesEventType = 'FM_READY' | 'FM_SELECT' | 'FM_EVENT' | 'FM_CLOSE';

interface FluxFilesSDK {
    open(options: FluxFilesOpenOptions): void;
    close(): void;
    command(action: string, data?: Record<string, unknown>): void;
    navigate(path: string): void;
    setDisk(disk: string): void;
    refresh(): void;
    search(query: string): void;
    crossCopy(dstDisk: string, dstPath?: string): void;
    crossMove(dstDisk: string, dstPath?: string): void;
    aiTag(): void;
    on(event: FluxFilesEventType, callback: (data: unknown) => void): () => void;
    off(event: FluxFilesEventType, callback: (data: unknown) => void): void;
}

declare const FluxFiles: FluxFilesSDK;

export = FluxFiles;
export as namespace FluxFiles;
