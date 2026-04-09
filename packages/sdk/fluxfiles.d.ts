interface FluxFilesOpenOptions {
    endpoint: string;
    token: string;
    disk?: string;
    /** Available disks shown in sidebar. */
    disks?: string[];
    mode?: 'picker' | 'browser';
    /** Allow multi-select; when true, onSelect receives array of FluxFile */
    multiple?: boolean;
    /** Locale code (e.g. "en", "vi", "ar"). Default: "en". */
    locale?: string;
    /** Theme: "light", "dark", or "auto". */
    theme?: string;
    allowedTypes?: string[];
    maxSize?: number;
    container?: string;
    onSelect?: (file: FluxFile | FluxFile[]) => void;
    onClose?: () => void;
    /** Called when the iframe needs a fresh JWT (401 received). Return new token or null. */
    onTokenRefresh?: (context: { reason: string; disk?: string; path?: string }) => Promise<string | null>;
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
    meta?: {
        title?: string | null;
        alt_text?: string | null;
        caption?: string | null;
        tags?: string | null;
    } | null;
    /** Image variant URLs (thumb/medium/large WebP). */
    variants?: Record<string, { url: string; key: string }> | null;
    /** Which variant was selected ("original", "thumb", "medium", "large"). */
    variant?: 'original' | 'thumb' | 'medium' | 'large';
}

interface FluxEvent {
    action: 'upload' | 'delete' | 'move' | 'copy' | 'mkdir' | 'restore' | 'purge' | 'trash' | 'crop' | 'ai_tag';
    disk: string;
    path: string;
    [key: string]: unknown;
}

type FluxFilesEventType = 'FM_READY' | 'FM_SELECT' | 'FM_EVENT' | 'FM_CLOSE' | 'FM_TOKEN_REFRESH';

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
    /** Switch locale/language at runtime. */
    setLocale(locale: string): void;
    /** Push a new token (e.g. after background refresh). */
    updateToken(token: string): void;
    on(event: FluxFilesEventType, callback: (data: unknown) => void): () => void;
    off(event: FluxFilesEventType, callback: (data: unknown) => void): void;
}

declare const FluxFiles: FluxFilesSDK;

export = FluxFiles;
export as namespace FluxFiles;
