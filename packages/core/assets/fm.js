function fluxFilesApp() {
    const LOCALE = window.__FM_LOCALE__ || { locale: 'en', dir: 'ltr', messages: {} };

    return {
        // i18n
        locale: LOCALE.locale,
        direction: LOCALE.dir,
        _messages: LOCALE.messages,
        _i18nForceReady: false, // safety net: reveal UI even if messages never load

        // State
        currentDisk: 'local',
        currentPath: '',
        files: [],
        folders: [],
        selected: [],
        view: 'grid',
        loading: false,
        loadError: null,            // string|null — set when loadFiles() fails (persistent, vs the transient toast)
        // Sidebar folder tree (lazy expand/collapse)
        expandedDirs: {},           // { dirKey: true } — which nodes are open
        dirChildren: {},            // { dirKey: [{key,name}] } — cached child folders ('' = root)
        treeLoading: {},            // { dirKey: true } — children being fetched
        // Drag-to-move
        dragItem: null,             // the file/folder being dragged (internal move)
        dropTarget: null,           // dir key currently hovered as a drop target
        token: '',
        endpoint: '',
        config: {},
        searchQuery: '',
        // Sort + filter state (persisted per-session)
        // Default to newest-first by date: with many folders/files it's easier to
        // scan recent items than to hunt alphabetically. Overridable + remembered.
        sortBy: 'date',     // 'name' | 'date' | 'size' | 'type'
        sortDir: 'desc',    // 'asc' | 'desc'
        typeFilter: 'all',  // 'all' | 'image' | 'video' | 'audio' | 'document' | 'other'
        sortMenuOpen: false,
        filterMenuOpen: false,
        searchResults: null, // null | array (files)
        searchFolderResults: null, // null | array (folders)
        searching: false,
        searchError: '',
        _searchTimer: null,
        _searchLastQ: '',
        _searchSeq: 0,
        _activeSearchId: 0,
        _pendingSelectKey: null,
        // Pagination state for /api/fm/list
        listLimit: 1000,
        listCursor: null,    // null = no more pages or not paginated
        listTotal: 0,
        loadingMore: false,
        detailFile: null,
        selectedVariant: 'original',
        activeTab: 'info',
        showConfirm: false,
        confirmAction: null,
        confirmMessage: '',

        // Cross-disk state
        showCrossDisk: false,
        crossDiskMode: 'copy', // 'copy' or 'move'
        crossDiskTarget: '',
        crossDiskPath: '',

        // Bulk operation state
        bulkBusy: false,
        bulkProgress: 0,
        bulkTotal: 0,
        bulkDone: 0,
        bulkAction: '',
        showBulkMove: false,
        bulkMoveTarget: '',

        // New folder modal
        showNewFolder: false,
        newFolderName: '',
        newFolderError: '',
        newFolderCreating: false,

        // Rename modal
        showRename: false,
        renameTarget: null,
        renameValue: '',     // editable base name (extension stripped for files)
        renameExt: '',       // locked extension suffix incl. dot (e.g. ".png"); '' for folders/extensionless
        renameError: '',
        renameSubmitting: false,

        // Activity log (audit) panel
        showActivity: false,
        activityEntries: [],
        activityLoading: false,
        activityError: '',
        activityFilter: { action: '', path: '', from: '', to: '' },

        // Bucket Doctor panel
        showDoctor: false,
        doctorReport: null,
        doctorLoading: false,
        doctorError: '',

        // Trash panel
        showTrash: false,
        trashEntries: [],
        trashLoading: false,
        trashError: '',

        // Import from URL
        showUrlImport: false,
        urlImportUrl: '',
        urlImportState: 'input', // 'input' | 'importing' | 'success' | 'error'
        urlImportError: '',
        urlImportResult: null,

        // Auth state
        authRequired: false,
        authState: 'ok', // 'ok' | 'missing' | 'expired' | 'refreshing'
        _refreshPromise: null, // coalesces concurrent refresh requests
        _refreshAttempts: 0, // prevents infinite refresh loops

        // Toast state
        toastMessage: '',
        toastType: 'success', // 'success' | 'error' | 'info'
        toastVisible: false,
        _toastTimer: null,

        // Theme: 'light' | 'dark' | 'auto'
        theme: 'auto',
        isDark: false,
        _themeMediaQuery: null,
        _themeMediaHandler: null,

        // Mobile: sidebar drawer + mobile UI state
        sidebarOpen: false,
        mobileSearchOpen: false,
        mobileMoreOpen: false,
        mobileActionSheet: null, // null or file/folder object for action sheet

        // Detail panel: resizable width (desktop), persisted
        detailPanelWidth: 350,
        _resizeDetail: null, // { startX, startW } when dragging

        // Preview lightbox
        previewFullscreen: false,

        // Upload state
        uploadProgress: 0,
        uploading: false,
        uploadCurrentName: '',   // file currently being sent
        uploadCurrentIndex: 0,   // 1-based index within the batch
        uploadTotal: 0,          // total files in the batch
        uploadPhase: 'uploading', // 'uploading' (bytes in flight) | 'processing' (server working)
        dragActive: false,

        // AI tag state
        aiTagging: false,
        aiTags: [],

        // Metadata state
        metaForm: { title: '', alt_text: '', caption: '' },
        metaSaving: false,
        metaSaveTimer: null,
        seoSectionExpanded: true, // accordion: collapse SEO fields to save space

        // Crop state
        cropActive: false,
        cropSaving: false,
        cropData: { x: 0, y: 0, w: 0, h: 0 },
        cropAspect: null, // null = free, '1:1', '16:9', '4:3'
        _cropDragging: false,
        _cropStart: { x: 0, y: 0 },
        _cropImgRect: null,
        _cropNatW: 0,
        _cropNatH: 0,

        // i18n helpers
        t(key, vars) {
            const parts = key.split('.');
            let val = this._messages;
            for (const p of parts) {
                val = val?.[p];
                if (val === undefined) return key;
            }
            if (typeof val !== 'string') return key;
            if (!vars) return val;
            return val.replace(/\{(\w+)\}/g, (_, k) => vars[k] !== undefined ? vars[k] : '{' + k + '}');
        },

        tp(singularKey, pluralKey, n, vars) {
            const key = n === 1 ? singularKey : pluralKey;
            return this.t(key, { ...vars, count: n });
        },

        // True once translation messages are available, so the UI can stay
        // behind a boot spinner until then instead of painting raw i18n keys
        // (happens when index.html is served without the server-side
        // __FM_LOCALE__ injection). Server-injected pages are ready on frame 1.
        get i18nReady() {
            return this._i18nForceReady || Object.keys(this._messages || {}).length > 0;
        },

        async switchLocale(newLocale) {
            if (newLocale === this.locale && Object.keys(this._messages).length > 1) return;
            try {
                const res = await fetch(this.joinUrl('/api/fm/lang/' + encodeURIComponent(newLocale)));
                if (!res.ok) return;
                const json = await res.json();
                const data = json.data;
                if (data && data.messages) {
                    this._messages = data.messages;
                    this.locale = data.locale;
                    this.direction = data.dir;
                    document.documentElement.dir = data.dir;
                    document.documentElement.lang = data.locale;
                }
            } catch (err) {
                console.error('FluxFiles: switchLocale failed', err);
            }
        },

        // Trusted parent origin (set on first FM_CONFIG message)
        _parentOrigin: null,

        // Init
        init() {
            // Safety net: never trap the UI behind the boot spinner. If messages
            // can't be fetched (offline, route error), reveal anyway after 3s —
            // raw keys are a last resort, an infinite spinner is worse.
            if (!this.i18nReady) {
                setTimeout(() => { this._i18nForceReady = true; }, 3000);
            }

            // Restore detail panel width from localStorage
            try {
                const w = parseInt(localStorage.getItem('fluxfiles_detail_width'), 10);
                if (w >= 280 && w <= 600) this.detailPanelWidth = w;
            } catch (_) {}

            // Restore sort + filter preferences
            try {
                const sb = localStorage.getItem('fluxfiles_sort_by');
                if (sb && ['name','date','size','type'].includes(sb)) this.sortBy = sb;
                const sd = localStorage.getItem('fluxfiles_sort_dir');
                if (sd === 'asc' || sd === 'desc') this.sortDir = sd;
                const tf = localStorage.getItem('fluxfiles_type_filter');
                if (tf && ['all','image','video','audio','document','other'].includes(tf)) this.typeFilter = tf;
            } catch (_) {}

            // Persist whenever they change
            this.$watch('sortBy', (v) => { try { localStorage.setItem('fluxfiles_sort_by', v); } catch (_) {} });
            this.$watch('sortDir', (v) => { try { localStorage.setItem('fluxfiles_sort_dir', v); } catch (_) {} });
            this.$watch('typeFilter', (v) => { try { localStorage.setItem('fluxfiles_type_filter', v); } catch (_) {} });

            // Close preview lightbox when detail file is cleared
            this.$watch('detailFile', (v) => { if (!v) this.previewFullscreen = false; });

            // Detail panel resize (desktop)
            this._resizeDetailMove = (e) => {
                if (!this._resizeDetail) return;
                const delta = this._resizeDetail.startX - e.clientX; // drag left = positive = wider
                let w = this._resizeDetail.startW + delta;
                w = Math.max(280, Math.min(600, w));
                this.detailPanelWidth = w;
            };
            this._resizeDetailUp = () => {
                if (!this._resizeDetail) return;
                this._resizeDetail = null;
                document.removeEventListener('mousemove', this._resizeDetailMove);
                document.removeEventListener('mouseup', this._resizeDetailUp);
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                try { localStorage.setItem('fluxfiles_detail_width', String(this.detailPanelWidth)); } catch (_) {}
            };

            // Global drag-and-drop guard. The dropzone is a small target; without
            // this, a file dropped anywhere else (e.g. a .zip onto the file grid)
            // triggers the browser's default "open the file" behaviour, which
            // navigates away and replaces the whole app with the raw file. Block
            // that everywhere, and treat a drop anywhere in the manager as an
            // upload (the dropzone keeps handling its own drops).
            window.addEventListener('dragover', (e) => { e.preventDefault(); });
            window.addEventListener('drop', (e) => {
                if (e.target && e.target.closest && e.target.closest('.ff-dropzone')) return;
                e.preventDefault();
                const dropped = e.dataTransfer && e.dataTransfer.files;
                if (dropped && dropped.length && this.token) this.uploadFiles(dropped);
            });

            window.addEventListener('message', (e) => {
                // Validate origin: trust first FM_CONFIG sender, then lock to that origin
                if (this._parentOrigin && e.origin !== this._parentOrigin) return;

                const msg = e.data;
                if (!msg || msg.source !== 'fluxfiles') return;

                if (msg.type === 'FM_CONFIG') {
                    if (!this._parentOrigin) this._parentOrigin = e.origin;
                    const p = msg.payload;

                    // Idempotency guard: chatty hosts (React/Vue re-renders, double
                    // sends) can post the same FM_CONFIG repeatedly. Only (re)load when
                    // something that affects the listing actually changed — otherwise a
                    // duplicate config would re-fire list + quota + lang every time.
                    const sig = [p.token || '', p.disk || 'local', p.path || '', p.locale || '', p.endpoint || ''].join('|');
                    const changed = sig !== this._lastConfigSig;
                    this._lastConfigSig = sig;

                    this.token = p.token || '';
                    this.currentDisk = p.disk || 'local';
                    this.endpoint = p.endpoint || '';
                    this.config = p;
                    if (p.path !== undefined) this.currentPath = p.path || '';
                    this.authRequired = false;
                    this._initTheme();

                    if (!changed) return; // same config already applied — no reload

                    // Handle locale from host app
                    // Priority: explicit SDK locale > server-injected locale > default 'en'
                    if (p.locale && p.locale !== 'auto') {
                        // Host app explicitly requested a locale
                        this.switchLocale(p.locale);
                    }
                    // else: keep server-injected locale (FLUXFILES_LOCALE) or default 'en'

                    this.loadFiles();
                    this.loadQuota();
                }

                // Host proactively pushed a new token (e.g. background refresh,
                // or in response to the expired screen's Retry).
                if (msg.type === 'FM_TOKEN_UPDATED' && msg.payload?.token) {
                    this.token = msg.payload.token;
                    this.authRequired = false;
                    this.authState = 'ok';
                    this._refreshAttempts = 0;
                    // A fresh token arrived while a load was broken → recover the view.
                    if (this.loadError) {
                        this.loadError = null;
                        this.loadFiles();
                        this.loadQuota();
                    }
                }

                if (msg.type === 'FM_COMMAND') {
                    this.handleCommand(msg.payload);
                }
            });

            // Theme: localStorage > config > auto
            this._initTheme();

            // Notify parent we're ready
            // Set document direction
            document.documentElement.dir = this.direction;
            document.documentElement.lang = this.locale;

            this.postMessage('FM_READY', {
                version: '0.2.2',
                locale: this.locale,
                capabilities: ['list', 'upload', 'delete', 'move', 'copy', 'mkdir', 'presign', 'metadata', 'cross-copy', 'cross-move', 'bulk-ops', 'ai-tag', 'i18n']
            });

            // Standalone mode: not in iframe, load locale + files directly
            if (window.parent === window) {
                this.endpoint = window.location.origin;
                const params = new URLSearchParams(window.location.search);
                if (params.get('token')) this.token = params.get('token');
                if (params.get('disk')) this.currentDisk = params.get('disk');
                const urlPath = params.get('path');
                if (urlPath !== null && urlPath !== '') this.currentPath = urlPath;
                this.config = {
                    disks: (params.get('disks') || 'local').split(','),
                    theme: params.get('theme') || null,
                    multiple: params.get('multiple') === '1' || params.get('multiple') === 'true',
                    maxUploadMb: params.get('maxUploadMb') ? parseInt(params.get('maxUploadMb'), 10) : null,
                    maxFiles: params.get('maxFiles') ? parseInt(params.get('maxFiles'), 10) : null
                };
                this._initTheme();

                // Locale priority: ?locale= > FLUXFILES_LOCALE on server > default 'en'
                const urlLocale = params.get('locale') || params.get('lang');
                const initLocale = async () => {
                    if (urlLocale) {
                        await this.switchLocale(urlLocale);
                    } else {
                        // Check if server configured a specific locale via FLUXFILES_LOCALE
                        try {
                            const res = await fetch(this.joinUrl('/api/fm/lang'));
                            const serverLocale = res.headers.get('Content-Language') || 'en';
                            // Load messages when the server resolved a non-default
                            // locale, OR when this page was served without the
                            // __FM_LOCALE__ injection (messages still empty) —
                            // otherwise English would render raw keys forever.
                            if (serverLocale !== 'en' || !this.i18nReady) {
                                await this.switchLocale(serverLocale);
                            }
                        } catch (_) {
                            // keep default 'en'
                        }
                    }
                    if (this.token) {
                        this.loadFiles();
                        this.loadQuota();
                    } else {
                        this.authState = 'missing';
                        this.authRequired = true;
                    }
                };
                initLocale();
            }
        },

        // Toast helper
        showToast(message, type = 'success', duration = 2500) {
            if (this._toastTimer) clearTimeout(this._toastTimer);
            this.toastMessage = message;
            this.toastType = type;
            this.toastVisible = true;
            this._toastTimer = setTimeout(() => { this.toastVisible = false; }, duration);
        },

        // PostMessage helper — serialize payload to avoid "Proxy object could not be cloned"
        postMessage(type, payload) {
            if (window.parent && window.parent !== window) {
                let safePayload;
                try {
                    safePayload = JSON.parse(JSON.stringify(payload ?? {}));
                } catch (_) {
                    safePayload = {};
                }
                window.parent.postMessage({
                    source: 'fluxfiles',
                    type: type,
                    v: 1,
                    id: 'ff-' + Math.random().toString(36).substr(2, 9),
                    payload: safePayload
                }, this._parentOrigin || '*');
            }
        },

        // Join the configured endpoint with a path that may already carry a `?`-
        // prefixed query string. Plain endpoints like `https://files.example.com`
        // concatenate cleanly, but proxy endpoints such as WordPress's
        // `…/index.php?rest_route=/fluxfiles/v1` already contain a `?`, so we
        // promote any subsequent `?` in `path` to `&` to avoid double-`?` URLs
        // that PHP would parse as one giant rest_route value.
        joinUrl(path) {
            const base = this.endpoint || '';
            if (base.indexOf('?') !== -1) {
                const qIdx = path.indexOf('?');
                if (qIdx !== -1) {
                    return base + path.slice(0, qIdx) + '&' + path.slice(qIdx + 1);
                }
            }
            return base + path;
        },

        // API helper — with 401 detection, token refresh queue, and retry
        async api(method, path, body, _isRetry = false) {
            const url = this.joinUrl(path);
            const opts = {
                method: method,
                headers: {
                    'Authorization': 'Bearer ' + this.token
                }
            };

            if (body instanceof FormData) {
                opts.body = body;
            } else if (body) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }

            const res = await fetch(url, opts);

            // 401 — token expired or invalid
            if (res.status === 401 && !_isRetry) {
                const refreshed = await this._handleTokenExpired();
                if (refreshed) {
                    // Retry the original request with new token
                    return this.api(method, path, body, true);
                }
                // Refresh failed — throw so caller's catch handles it
                throw new Error('Session expired');
            }

            const json = await res.json();

            if (json.error) {
                // Try i18n error code first, fall back to raw message
                var msg = null;
                if (json.error_code) {
                    msg = this.t('error.' + json.error_code, json.error_params || {});
                    if (msg === 'error.' + json.error_code) {
                        msg = null; // key not found, fall back
                    }
                }
                throw new Error(msg || json.error);
            }

            // Reset refresh attempts on any successful request
            this._refreshAttempts = 0;
            return json.data;
        },

        /**
         * Handle token expiration:
         * 1. Notify host app via FM_TOKEN_REFRESH
         * 2. Wait for FM_TOKEN_UPDATED with new token (or timeout)
         * 3. If host doesn't respond, show auth expired screen
         *
         * Multiple concurrent 401s coalesce into ONE refresh request.
         * Returns true if token was refreshed, false if not.
         */
        async _handleTokenExpired() {
            // Coalesce FIRST: concurrent 401s (list + quota + variants…) share ONE
            // refresh cycle. They must wait on the in-flight refresh rather than
            // each spending the attempt budget — otherwise a few parallel requests
            // exhaust it instantly and the manual Retry can never refresh.
            if (this._refreshPromise) {
                return this._refreshPromise;
            }

            // Prevent infinite AUTO-refresh loops (counted per cycle, not per request).
            this._refreshAttempts++;
            if (this._refreshAttempts > 2) {
                this._showAuthExpired();
                return false;
            }

            this.authState = 'refreshing';

            this._refreshPromise = new Promise((resolve) => {
                // Ask host app for a new token
                this.postMessage('FM_TOKEN_REFRESH', {
                    reason: 'expired',
                    disk: this.currentDisk,
                    path: this.currentPath
                });

                // Listen for response (with timeout)
                const timeout = setTimeout(() => {
                    cleanup();
                    this._showAuthExpired();
                    resolve(false);
                }, 10000); // 10s timeout

                const handler = (e) => {
                    if (this._parentOrigin && e.origin !== this._parentOrigin) return;
                    const msg = e.data;
                    if (!msg || msg.source !== 'fluxfiles') return;

                    if (msg.type === 'FM_TOKEN_UPDATED' && msg.payload?.token) {
                        cleanup();
                        this.token = msg.payload.token;
                        this.authRequired = false;
                        this.authState = 'ok';
                        this.postMessage('FM_EVENT', { event: 'auth:refreshed' });
                        resolve(true);
                    }

                    if (msg.type === 'FM_TOKEN_FAILED') {
                        cleanup();
                        this._showAuthExpired();
                        resolve(false);
                    }
                };

                const cleanup = () => {
                    clearTimeout(timeout);
                    window.removeEventListener('message', handler);
                    this._refreshPromise = null;
                };

                window.addEventListener('message', handler);
            });

            return this._refreshPromise;
        },

        _showAuthExpired() {
            this.authState = 'expired';
            this.authRequired = true;
            this._refreshPromise = null;
            this.postMessage('FM_EVENT', { event: 'auth:expired' });
        },

        // Load files (first page)
        async loadFiles() {
            this.loading = true;
            this.loadError = null;
            this.listCursor = null;
            this.listTotal = 0;
            try {
                const res = await this.api('GET',
                    '/api/fm/list?disk=' + encodeURIComponent(this.currentDisk) +
                    '&path=' + encodeURIComponent(this.currentPath) +
                    '&limit=' + encodeURIComponent(this.listLimit)
                );

                // Paginated mode returns {items, next_cursor, total}; legacy mode returns a flat array.
                const items = Array.isArray(res) ? res : (res?.items || []);
                this.listCursor = Array.isArray(res) ? null : (res?.next_cursor ?? null);
                this.listTotal = Array.isArray(res) ? items.length : (res?.total ?? items.length);
                this.diskDriver = Array.isArray(res) ? '' : (res?.disk_driver ?? '');

                this.folders = items.filter(i => i.type === 'dir');
                this.files = items.filter(i => i.type === 'file');
                this.selected = [];
                this.detailFile = null;

                // Seed the tree cache with the current dir's children (free — we
                // just fetched them) and auto-expand the trail down to here.
                this.dirChildren[this.currentPath] = this.folders.map(d => ({ key: d.key, name: d.name }));
                this.syncTreeToPath();

                // If the target file from a global search isn't on the first page, keep loading until we find it or pages run out.
                if (this._pendingSelectKey) {
                    await this._resolvePendingSelection();
                }
            } catch (err) {
                console.error('FluxFiles: Failed to load files', err);
                // Persistent inline error (+ retry) so a failed load isn't mistaken
                // for an empty folder once the transient toast fades.
                this.loadError = err.message || this.t('error.load_failed');
                this.folders = [];
                this.files = [];
                this.showToast(this.loadError, 'error', 4000);
            } finally {
                this.loading = false;
            }
        },

        // User-initiated retry after a load error (the Retry button). A manual
        // retry is a fresh start: clear the auto-refresh loop guard so a
        // previously-exhausted budget doesn't make Retry a no-op, drop the
        // expired screen, then reload — which re-asks the host for a token on 401.
        retryLoad() {
            this._refreshAttempts = 0;
            this._refreshPromise = null;
            this.loadError = null;
            if (this.authState === 'expired') {
                this.authRequired = false;
                this.authState = 'ok';
            }
            this.loadFiles();
            this.loadQuota();
        },

        // Load the next page and append to existing folders/files.
        async loadMoreFiles() {
            if (!this.listCursor || this.loadingMore) return;
            this.loadingMore = true;
            try {
                const res = await this.api('GET',
                    '/api/fm/list?disk=' + encodeURIComponent(this.currentDisk) +
                    '&path=' + encodeURIComponent(this.currentPath) +
                    '&limit=' + encodeURIComponent(this.listLimit) +
                    '&cursor=' + encodeURIComponent(this.listCursor)
                );

                const items = Array.isArray(res) ? res : (res?.items || []);
                this.listCursor = Array.isArray(res) ? null : (res?.next_cursor ?? null);

                // Dedupe by key in case of overlap.
                const existing = new Set([...this.folders, ...this.files].map(i => i.key));
                for (const it of items) {
                    if (existing.has(it.key)) continue;
                    if (it.type === 'dir') this.folders.push(it);
                    else this.files.push(it);
                }
            } catch (err) {
                console.error('FluxFiles: Failed to load more files', err);
                this.showToast(err.message || this.t('error.generic'), 'error', 4000);
            } finally {
                this.loadingMore = false;
            }
        },

        async _resolvePendingSelection() {
            const key = this._pendingSelectKey;
            if (!key) return;
            let found = this.files.find(f => f && f.key === key);
            // Keep paging until we find it or there are no more pages.
            while (!found && this.listCursor) {
                await this.loadMoreFiles();
                found = this.files.find(f => f && f.key === key);
            }
            this._pendingSelectKey = null;
            if (found) {
                this.selected = [found];
                this.detailFile = found;
            }
        },

        // Global search (across disk via metadata index)
        _scheduleSearch() {
            const q = (this.searchQuery || '').trim();

            // Clear search state when query is empty or too short
            if (q.length < 2) {
                this.searchResults = null;
                this.searchFolderResults = null;
                this.searchError = '';
                this.searching = false;
                this._searchLastQ = q;
                this._activeSearchId = 0;
                if (this._searchTimer) {
                    clearTimeout(this._searchTimer);
                    this._searchTimer = null;
                }
                return;
            }

            // Debounce
            if (this._searchTimer) clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => {
                this._searchTimer = null;
                this.runSearch(q);
            }, 250);
        },

        async runSearch(q) {
            const query = (q || '').trim();
            if (query.length < 2) return;

            // Avoid duplicate calls for same query
            if (this.searching && this._searchLastQ === query) return;
            this._searchLastQ = query;
            const reqId = ++this._searchSeq;
            this._activeSearchId = reqId;
            this.searching = true;
            this.searchError = '';

            try {
                const filesUrl =
                    '/api/fm/search?disk=' + encodeURIComponent(this.currentDisk) +
                    '&q=' + encodeURIComponent(query) +
                    '&limit=' + encodeURIComponent(200);
                const foldersUrl =
                    '/api/fm/search-folders?disk=' + encodeURIComponent(this.currentDisk) +
                    '&q=' + encodeURIComponent(query) +
                    '&limit=' + encodeURIComponent(200);

                const [fileRows, folderRows] = await Promise.all([
                    this.api('GET', filesUrl),
                    this.api('GET', foldersUrl),
                ]);

                // Ignore stale responses when user typed a newer query
                if (this._activeSearchId !== reqId) return;

                this.searchResults = Array.isArray(fileRows) ? fileRows : [];
                this.searchFolderResults = Array.isArray(folderRows) ? folderRows : [];
            } catch (err) {
                console.error('FluxFiles: Search failed', err);

                if (this._activeSearchId !== reqId) return;

                this.searchResults = [];
                this.searchFolderResults = [];
                this.searchError = err?.message || 'Search failed';
            } finally {
                if (this._activeSearchId === reqId) {
                    this.searching = false;
                }
            }
        },

        openSearchResult(row) {
            const key = row?.file_key || row?.key;
            if (!key) return;
            const s = String(key);
            const idx = s.lastIndexOf('/');
            const dir = idx >= 0 ? s.slice(0, idx) : '';

            // Exit search view and navigate to folder containing the file
            this.searchQuery = '';
            this.searchResults = null;
            this.searchFolderResults = null;
            this.searchError = '';
            this._pendingSelectKey = s;
            this.navigate(dir);
        },

        openSearchFolder(row) {
            const key = row?.dir_key || row?.key;
            if (!key) return;
            const s = String(key);
            this.searchQuery = '';
            this.searchResults = null;
            this.searchFolderResults = null;
            this.searchError = '';
            this.navigate(s);
        },

        // Navigation
        navigate(path) {
            this.currentPath = path;
            this._updateUrlPath();
            this.loadFiles();
            this.sidebarOpen = false;
        },

        _updateUrlPath() {
            if (window.parent !== window) return;
            const url = new URL(window.location.href);
            if (this.currentPath) {
                url.searchParams.set('path', this.currentPath);
            } else {
                url.searchParams.delete('path');
            }
            window.history.replaceState({}, '', url.toString());
        },

        navigateUp() {
            const parts = this.currentPath.split('/').filter(Boolean);
            parts.pop();
            this.navigate(parts.join('/'));
        },

        // ── Sidebar folder tree (lazy expand/collapse) ──────────────────────
        // Fetch a directory's child folders once and cache them ('' = root).
        async loadDirChildren(key) {
            if (this.dirChildren[key] !== undefined || this.treeLoading[key]) return;
            this.treeLoading[key] = true;
            try {
                const res = await this.api('GET',
                    '/api/fm/list?disk=' + encodeURIComponent(this.currentDisk) +
                    '&path=' + encodeURIComponent(key) +
                    '&limit=' + encodeURIComponent(this.listLimit));
                const items = Array.isArray(res) ? res : (res?.items || []);
                this.dirChildren[key] = items
                    .filter(i => i.type === 'dir')
                    .map(d => ({ key: d.key, name: d.name }));
            } catch (e) {
                this.dirChildren[key] = []; // best-effort: one bad node shouldn't break the tree
            } finally {
                delete this.treeLoading[key];
            }
        },

        async toggleDir(key) {
            if (this.expandedDirs[key]) {
                delete this.expandedDirs[key];
            } else {
                this.expandedDirs[key] = true;
                await this.loadDirChildren(key);
            }
        },

        // Auto-expand the trail down to the current folder so the tree always
        // shows where you are (the breadcrumb's vertical twin) while still
        // letting you open sibling branches.
        async syncTreeToPath() {
            await this.loadDirChildren('');
            const parts = this.currentPath.split('/').filter(Boolean);
            let cum = '';
            for (const p of parts) {
                cum = cum ? cum + '/' + p : p;
                this.expandedDirs[cum] = true;
                await this.loadDirChildren(cum);
            }
        },

        // Flatten the expanded tree into renderable rows (Alpine has no recursion).
        get flatTree() {
            const rows = [];
            const walk = (key, depth) => {
                for (const child of (this.dirChildren[key] || [])) {
                    const loaded = this.dirChildren[child.key];
                    rows.push({
                        key: child.key,
                        name: child.name,
                        depth,
                        expanded: !!this.expandedDirs[child.key],
                        loading: !!this.treeLoading[child.key],
                        // Unknown until loaded → show the chevron optimistically.
                        hasChildren: loaded === undefined ? true : loaded.length > 0,
                    });
                    if (this.expandedDirs[child.key]) walk(child.key, depth + 1);
                }
            };
            walk('', 0);
            return rows;
        },

        // ── Drag a file/folder onto a folder (sidebar or grid) to move it ────
        onItemDragStart(item, ev) {
            this.dragItem = item;
            try {
                ev.dataTransfer.effectAllowed = 'move';
                ev.dataTransfer.setData('application/x-fluxfiles', item.key);
            } catch (e) { /* some browsers restrict setData */ }
        },
        onItemDragEnd() { this.dragItem = null; this.dropTarget = null; },

        // Only react to OUR internal drags — an OS file drag (upload) has no dragItem.
        onFolderDragOver(key, ev) {
            if (!this.dragItem) return;
            ev.preventDefault();
            try { ev.dataTransfer.dropEffect = 'move'; } catch (e) {}
            this.dropTarget = key;
        },
        onFolderDragLeave(key) { if (this.dropTarget === key) this.dropTarget = null; },

        async onFolderDrop(targetKey, ev) {
            if (!this.dragItem) return;           // external file drop → leave it to the upload dropzone
            if (ev) ev.preventDefault();
            const dragged = this.dragItem;
            this.dragItem = null;
            this.dropTarget = null;
            // Move the whole selection if the dragged item is part of a multi-select.
            const items = (this.selected.some(s => s.key === dragged.key) && this.selected.length > 1)
                ? [...this.selected] : [dragged];
            await this.moveItemsTo(items, targetKey);
        },

        async moveItemsTo(items, targetDir) {
            // Filter out no-ops and illegal moves up front so the progress count is honest.
            const moves = items.filter((it) => {
                const parent = it.key.includes('/') ? it.key.slice(0, it.key.lastIndexOf('/')) : '';
                if (parent === targetDir) return false;                      // already there
                if (it.type === 'dir' && (targetDir === it.key || targetDir.startsWith(it.key + '/'))) {
                    this.showToast(this.t('error.move_into_self') || 'Cannot move a folder into itself', 'error', 4000);
                    return false;
                }
                return true;
            });
            if (moves.length === 0) return;

            this.startBulk('Moving', moves.length);
            let errors = 0;
            for (const it of moves) {
                try {
                    const dest = (targetDir ? targetDir + '/' : '') + it.name;
                    await this.api('POST', '/api/fm/move', { disk: this.currentDisk, from: it.key, to: dest });
                    this.postMessage('FM_EVENT', { event: 'move:done', key: it.key, to: dest });
                } catch (err) {
                    errors++;
                    console.error('FluxFiles: move failed', it.key, err);
                    this.showToast(err.message || this.t('error.generic'), 'error', 4000);
                }
                this.tickBulk();
            }
            this.endBulk();
            if (errors === 0) this.showToast(this.t('common.success'), 'success');
            this.selected = [];
            this.detailFile = null;
            this.dirChildren = {};   // invalidate the tree cache (moved subtrees changed)
            this.loadFiles();
        },

        get breadcrumbs() {
            const parts = this.currentPath.split('/').filter(Boolean);
            const crumbs = [{ name: this.t('common.root') || 'All files', path: '' }];
            let cumulative = '';
            for (const part of parts) {
                cumulative += (cumulative ? '/' : '') + part;
                crumbs.push({ name: part, path: cumulative });
            }
            return crumbs;
        },

        // Disk switching
        switchDisk(disk) {
            this.currentDisk = disk;
            this.currentPath = '';
            this._updateUrlPath();
            this.loadFiles();
            this.loadQuota();
            this.sidebarOpen = false;
        },

        // Build FM_SELECT payload from file/folder
        _toSelectPayload(item) {
            return {
                url: item.url,
                key: item.key,
                name: item.name,
                path: item.key,      // backward compat
                basename: item.name, // backward compat
                size: item.size,
                disk: this.currentDisk,
                permanent_url: item.permanent_url || null,
                mime: item.mime || (item.meta && item.meta.mime) || null,
                width: item.width || (item.meta && item.meta.width) || null,
                height: item.height || (item.meta && item.meta.height) || null,
                meta: item.meta || null,
                variants: item.variants || null,
                type: item.type || 'file',
                is_dir: item.type === 'dir'
            };
        },

        // File selection (single — from detail panel)
        selectFile(file) {
            this.detailFile = file;
            this.selectedVariant = 'original';
            this.activeTab = 'info';

            // Load metadata into form
            if (file.meta) {
                this.metaForm = {
                    title: file.meta.title || '',
                    alt_text: file.meta.alt_text || '',
                    caption: file.meta.caption || ''
                };
                this.aiTags = file.meta.tags ? file.meta.tags.split(', ').filter(Boolean) : [];
            } else {
                this.metaForm = { title: '', alt_text: '', caption: '' };
                this.aiTags = [];
            }

            // Notify parent (single object)
            this.postMessage('FM_SELECT', this._toSelectPayload(file));
        },

        // Get URL for currently selected variant (or original)
        getActiveUrl(file) {
            if (!file) return '';
            if (this.selectedVariant !== 'original' && file.variants && file.variants[this.selectedVariant]) {
                return file.variants[this.selectedVariant].url;
            }
            return file.url;
        },

        previewUrl(file, preferred = 'thumb') {
            if (!file) return '';
            if (file.variants) {
                if (preferred && file.variants[preferred] && file.variants[preferred].url) {
                    return file.variants[preferred].url;
                }
                if (file.variants.thumb && file.variants.thumb.url) {
                    return file.variants.thumb.url;
                }
            }
            return file.url || '';
        },

        // Select a specific variant (thumb/medium/large) or 'original'
        selectVariant(file, size) {
            this.selectedVariant = size;
            var payload = this._toSelectPayload(file);
            if (size !== 'original' && file.variants && file.variants[size]) {
                payload.url = file.variants[size].url;
                payload.key = file.variants[size].key;
                payload.variant = size;
            } else {
                payload.variant = 'original';
            }
            this.postMessage('FM_SELECT', payload);
            this.showToast(this.t('variants.selected', { size: size === 'original' ? this.t('variants.original') : this.t('variants.' + size) }), 'success');
        },

        // Multi-select: send selected items as array (when config.multiple)
        selectMultiple() {
            if (this.selected.length === 0) return;
            const payload = this.selected.map(item => this._toSelectPayload(item));
            this.postMessage('FM_SELECT', payload);
        },

        toggleSelect(file, event) {
            if (event && (event.ctrlKey || event.metaKey)) {
                const idx = this.selected.findIndex(s => s.key === file.key);
                if (idx >= 0) {
                    this.selected.splice(idx, 1);
                } else {
                    this.selected.push(file);
                }
            } else {
                this.selected = [file];
                // Show detail panel — user clicks "Select" or picks a variant to confirm
                this.detailFile = file;
                this.selectedVariant = 'original';
                this.activeTab = 'info';
                if (file.meta) {
                    this.metaForm = {
                        title: file.meta.title || '',
                        alt_text: file.meta.alt_text || '',
                        caption: file.meta.caption || ''
                    };
                    this.aiTags = file.meta.tags ? file.meta.tags.split(', ').filter(Boolean) : [];
                } else {
                    this.metaForm = { title: '', alt_text: '', caption: '' };
                    this.aiTags = [];
                }
            }
        },

        isSelected(file) {
            return this.selected.some(s => s.key === file.key);
        },

        // Select all / deselect all (folders + files)
        selectAll() {
            this.selected = [...this.filteredFolders, ...this.filteredFiles];
        },

        deselectAll() {
            this.selected = [];
            this.detailFile = null;
        },

        startResizeDetail(e) {
            e.preventDefault();
            this._resizeDetail = { startX: e.clientX, startW: this.detailPanelWidth };
            document.addEventListener('mousemove', this._resizeDetailMove);
            document.addEventListener('mouseup', this._resizeDetailUp);
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        },

        toggleSelectAll() {
            const total = this.filteredFolders.length + this.filteredFiles.length;
            if (total > 0 && this.selected.length === total) {
                this.deselectAll();
            } else {
                this.selectAll();
            }
        },

        get allSelected() {
            const total = this.filteredFolders.length + this.filteredFiles.length;
            return total > 0 && this.selected.length === total;
        },

        // Shift+click range select (folders + files in display order)
        shiftSelect(file, event) {
            if (event && event.shiftKey && this.selected.length > 0) {
                const allItems = [...this.filteredFolders, ...this.filteredFiles];
                const lastSelected = this.selected[this.selected.length - 1];
                const lastIdx = allItems.findIndex(f => f.key === lastSelected.key);
                const currIdx = allItems.findIndex(f => f.key === file.key);

                if (lastIdx >= 0 && currIdx >= 0) {
                    const start = Math.min(lastIdx, currIdx);
                    const end = Math.max(lastIdx, currIdx);
                    const range = allItems.slice(start, end + 1);

                    const keys = new Set(this.selected.map(s => s.key));
                    for (const f of range) {
                        if (!keys.has(f.key)) {
                            this.selected.push(f);
                        }
                    }
                    return true;
                }
            }
            return false;
        },

        handleFileClick(file, event) {
            if (event && event.shiftKey) {
                if (this.shiftSelect(file, event)) return;
            }
            this.toggleSelect(file, event);
        },

        toggleFolderSelect(folder, event) {
            if (event && (event.ctrlKey || event.metaKey)) {
                // Multi-select: toggle folder in selection
                const idx = this.selected.findIndex(s => s.key === folder.key);
                if (idx >= 0) {
                    this.selected.splice(idx, 1);
                } else {
                    this.selected.push(folder);
                }
            } else {
                // Single click: select only this folder, show in detail
                this.selected = [folder];
                this.detailFile = folder;
                this.activeTab = 'info';
            }
        },

        folderContextMenu(folder, event) {
            // Mobile: show action sheet instead of context menu
            if (window.innerWidth <= 768) {
                this.mobileActionSheet = folder;
                return;
            }
            // Desktop: select folder and trigger delete confirm
            this.selected = [folder];
            this.confirmDelete();
        },

        openActionSheet(item) {
            this.mobileActionSheet = item;
        },

        closeActionSheet() {
            this.mobileActionSheet = null;
        },

        // Bulk progress helper
        startBulk(action, total) {
            this.bulkBusy = true;
            this.bulkAction = action;
            this.bulkTotal = total;
            this.bulkDone = 0;
            this.bulkProgress = 0;
        },

        tickBulk() {
            this.bulkDone++;
            this.bulkProgress = Math.round((this.bulkDone / this.bulkTotal) * 100);
        },

        endBulk() {
            this.bulkBusy = false;
            this.bulkProgress = 0;
            this.bulkTotal = 0;
            this.bulkDone = 0;
            this.bulkAction = '';
        },

        // Bulk move (same disk — to a folder)
        openBulkMove() {
            if (this.selected.length === 0) return;
            this.bulkMoveTarget = this.currentPath;
            this.showBulkMove = true;
        },

        async executeBulkMove() {
            if (!this.bulkMoveTarget && this.bulkMoveTarget !== '') return;

            this.showBulkMove = false;
            this.startBulk('Moving', this.selected.length);

            var errors = 0;
            for (const file of [...this.selected]) {
                try {
                    const destPath = (this.bulkMoveTarget ? this.bulkMoveTarget + '/' : '') + file.name;
                    await this.api('POST', '/api/fm/move', {
                        disk: this.currentDisk,
                        from: file.key,
                        to: destPath
                    });
                    this.postMessage('FM_EVENT', { event: 'move:done', key: file.key, to: destPath });
                } catch (err) {
                    errors++;
                    console.error('FluxFiles: Bulk move failed', file.key, err);
                    this.showToast(err.message || this.t('error.generic'), 'error', 4000);
                }
                this.tickBulk();
            }

            this.endBulk();
            if (errors === 0) {
                this.showToast(this.t('common.success'), 'success');
            }
            this.selected = [];
            this.detailFile = null;
            this.loadFiles();
        },

        // Bulk download (sequential) — files only; folders have no direct URL.
        async bulkDownload() {
            const files = this.selected.filter(f => f.type !== 'dir' && f.url);
            if (files.length === 0) return;
            for (const file of files) {
                this.downloadFile(file);
                // Small delay so browser doesn't block multiple downloads
                await new Promise(r => setTimeout(r, 300));
            }
        },

        // Upload

        async uploadFiles(fileList) {
            if (!fileList || fileList.length === 0) return;
            let files = Array.from(fileList);

            // Client-side size guard (MB). The server also enforces max_upload, but
            // checking here avoids uploading bytes that will be rejected with 413.
            const maxMb = (this.config && typeof this.config.maxUploadMb === 'number')
                ? this.config.maxUploadMb : 0;
            if (maxMb > 0) {
                const tooBig = files.filter(f => f.size > maxMb * 1024 * 1024);
                if (tooBig.length) {
                    this.showToast(this.t('error.upload_too_large', { max: maxMb + 'MB' }), 'error', 4000);
                    files = files.filter(f => f.size <= maxMb * 1024 * 1024);
                }
                if (files.length === 0) return;
            }

            // Client-side file-count guard. The server enforces the true total under
            // the prefix; this catches an oversized batch early. Cap the batch to
            // maxFiles so a single drop of too many files is rejected up front.
            const maxFiles = (this.config && typeof this.config.maxFiles === 'number')
                ? this.config.maxFiles : 0;
            if (maxFiles > 0 && files.length > maxFiles) {
                this.showToast(this.t('error.too_many_files', { max: maxFiles }), 'error', 4000);
                files = files.slice(0, maxFiles);
            }

            const total = files.length;

            this.uploading = true;
            this.uploadProgress = 0;
            this.uploadTotal = total;

            // Overall % = (fully-done files + current file's byte fraction) / total.
            const setOverall = (index, frac) => {
                this.uploadProgress = Math.min(100, Math.round(((index + frac) / total) * 100));
            };

            let succeeded = 0;
            for (let i = 0; i < total; i++) {
                const file = files[i];
                this.uploadCurrentIndex = i + 1;
                this.uploadCurrentName = file.name;
                this.uploadPhase = 'uploading';
                setOverall(i, 0);

                try {
                    const onFrac = (frac) => {
                        // Bytes done but awaiting the response → server is processing
                        // (e.g. generating WebP variants for images).
                        this.uploadPhase = frac >= 1 ? 'processing' : 'uploading';
                        setOverall(i, Math.max(0, Math.min(1, frac)));
                    };

                    if (file.size > 10 * 1024 * 1024 && this.currentDisk !== 'local') {
                        await this.chunkUpload(file, this.currentDisk, this.currentPath, onFrac);
                    } else {
                        await this.uploadOne(file, onFrac);
                    }

                    succeeded++;
                    setOverall(i + 1, 0); // snap to the file boundary
                    this.postMessage('FM_EVENT', { event: 'upload:done', name: file.name });
                } catch (err) {
                    console.error('FluxFiles: Upload failed', file.name, err);
                    this.showToast((err && err.message) || this.t('error.generic'), 'error', 4000);
                    // Still advance the bar so it reflects items processed, not just succeeded.
                    setOverall(i + 1, 0);
                }
            }

            this.uploading = false;
            this.uploadProgress = 0;
            this.uploadCurrentName = '';
            this.uploadCurrentIndex = 0;
            this.uploadTotal = 0;
            this.uploadPhase = 'uploading';
            this.loadFiles();
        },

        // Single-file upload over XHR so we get real byte-level upload progress
        // (fetch() can't report upload progress). Mirrors api()'s 401 token-refresh
        // retry and i18n error-code mapping.
        uploadOne(file, onProgress, _isRetry = false) {
            return new Promise((resolve, reject) => {
                const form = new FormData();
                form.append('disk', this.currentDisk);
                form.append('path', this.currentPath);
                form.append('file', file);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', this.joinUrl('/api/fm/upload'));
                xhr.setRequestHeader('Authorization', 'Bearer ' + this.token);

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total);
                };
                // Whole body sent → hand off to the server (variant generation, etc.).
                xhr.upload.onload = () => { if (onProgress) onProgress(1); };

                xhr.onerror = () => reject(new Error(this.t('error.generic') || 'Upload failed'));
                xhr.onload = async () => {
                    // 401 → coalesced token refresh + single retry, like api().
                    if (xhr.status === 401 && !_isRetry) {
                        const refreshed = await this._handleTokenExpired();
                        if (refreshed) {
                            try { resolve(await this.uploadOne(file, onProgress, true)); }
                            catch (e) { reject(e); }
                        } else {
                            reject(new Error('Session expired'));
                        }
                        return;
                    }

                    let json = null;
                    try { json = JSON.parse(xhr.responseText); } catch (_) {}

                    if (xhr.status >= 200 && xhr.status < 300 && json && !json.error) {
                        this._refreshAttempts = 0;
                        resolve(json.data);
                        return;
                    }

                    // Prefer i18n error code, fall back to raw message.
                    let msg = null;
                    if (json && json.error_code) {
                        msg = this.t('error.' + json.error_code, json.error_params || {});
                        if (msg === 'error.' + json.error_code) msg = null;
                    }
                    reject(new Error(msg || (json && json.error) || ('HTTP ' + xhr.status)));
                };

                xhr.send(form);
            });
        },

        handleDrop(event) {
            event.preventDefault();
            this.dragActive = false;
            const files = event.dataTransfer?.files;
            if (files) this.uploadFiles(files);
        },

        triggerUpload() {
            this.$refs.fileInput?.click();
        },

        handleFileInput(event) {
            this.uploadFiles(event.target.files);
            event.target.value = '';
        },

        // Chunk upload for large files (>10MB) on S3/R2
        async chunkUpload(file, disk, path, onProgress) {
            const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB
            const MAX_CONCURRENT = 3;
            let key = (path ? path + '/' : '') + file.name;

            // Initiate multipart upload
            const initData = await this.api('POST', '/api/fm/chunk/init', { disk, path: key, size: file.size });
            const uploadId = initData.upload_id;
            key = initData.key || key;
            const totalParts = Math.ceil(file.size / CHUNK_SIZE);

            const parts = [];
            let completedParts = 0;

            // Upload chunks with concurrency limit
            const uploadPart = async (partNumber) => {
                const start = (partNumber - 1) * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);

                // Get presigned URL for this part
                const presignData = await this.api('POST', '/api/fm/chunk/presign', {
                    disk, key, upload_id: uploadId, part_number: partNumber
                });

                // Upload chunk directly to S3/R2
                const res = await fetch(presignData.url, {
                    method: 'PUT',
                    body: chunk
                });

                completedParts++;
                if (onProgress) onProgress(completedParts / totalParts);

                return {
                    PartNumber: partNumber,
                    ETag: res.headers.get('ETag')
                };
            };

            try {
                // Process in batches of MAX_CONCURRENT
                for (let i = 0; i < totalParts; i += MAX_CONCURRENT) {
                    const batch = [];
                    for (let j = i; j < Math.min(i + MAX_CONCURRENT, totalParts); j++) {
                        batch.push(uploadPart(j + 1));
                    }
                    const results = await Promise.all(batch);
                    parts.push(...results);
                }

                // Complete the multipart upload
                await this.api('POST', '/api/fm/chunk/complete', {
                    disk, key, upload_id: uploadId, parts
                });

                return true;
            } catch (err) {
                // Abort on failure
                try {
                    await this.api('POST', '/api/fm/chunk/abort', { disk, key, upload_id: uploadId });
                } catch (_) {}
                throw err;
            }
        },

        // Delete
        async deleteSelected() {
            this.showConfirm = false;
            this.startBulk('Deleting', this.selected.length);

            var errors = 0;
            for (const file of [...this.selected]) {
                try {
                    // Soft-delete files and folders to trash (restorable).
                    await this.api('POST', '/api/fm/trash', { disk: this.currentDisk, path: file.key });
                    this.postMessage('FM_EVENT', { event: 'trash:done', key: file.key });
                } catch (err) {
                    errors++;
                    console.error('FluxFiles: Delete failed', file.key, err);
                    this.showToast(err.message || this.t('error.generic'), 'error', 4000);
                }
                this.tickBulk();
            }

            this.endBulk();
            if (errors === 0) {
                this.showToast(this.t('trash.moved'), 'success');
            }
            this.selected = [];
            this.detailFile = null;
            this.loadFiles();
        },

        confirmDelete() {
            const folders = this.selected.filter(f => f.type === 'dir');
            const files = this.selected.filter(f => f.type !== 'dir');

            if (folders.length > 0 && this.selected.length === 1) {
                // Single folder delete
                this.confirmMessage = this.t('delete.confirm_folder', { name: folders[0].name });
            } else if (folders.length > 0) {
                // Mixed or multiple folders
                this.confirmMessage = this.t('delete.confirm_bulk_folders', {
                    count: this.selected.length,
                    folders: folders.length
                });
            } else if (files.length === 1) {
                this.confirmMessage = this.t('delete.confirm_file', { name: files[0].name });
            } else {
                this.confirmMessage = this.t('delete.confirm_bulk', { count: this.selected.length });
            }
            this.confirmAction = () => this.deleteSelected();
            this.showConfirm = true;
        },

        // Cross-disk copy/move
        openCrossDisk(mode) {
            if (this.selected.length === 0) return;
            this.crossDiskMode = mode;
            this.crossDiskTarget = '';
            this.crossDiskPath = this.currentPath;
            this.showCrossDisk = true;
        },

        get availableDisks() {
            const disks = (this.config && this.config.disks) || [];
            // If no disk list from config, try common defaults
            if (disks.length === 0) {
                return ['local', 's3', 'r2'].filter(d => d !== this.currentDisk);
            }
            return disks.filter(d => d !== this.currentDisk);
        },

        async executeCrossDisk() {
            if (!this.crossDiskTarget) return;

            const action = this.crossDiskMode === 'move' ? 'cross-move' : 'cross-copy';
            const label = this.crossDiskMode === 'move' ? 'Moving' : 'Copying';
            this.showCrossDisk = false;
            this.startBulk(label, this.selected.length);

            var errors = 0;
            for (const file of [...this.selected]) {
                try {
                    const dstPath = (this.crossDiskPath ? this.crossDiskPath + '/' : '') + file.name;
                    await this.api('POST', '/api/fm/' + action, {
                        src_disk: this.currentDisk,
                        src_path: file.key,
                        dst_disk: this.crossDiskTarget,
                        dst_path: dstPath
                    });
                    this.postMessage('FM_EVENT', {
                        event: action + ':done',
                        key: file.key,
                        src_disk: this.currentDisk,
                        dst_disk: this.crossDiskTarget
                    });
                } catch (err) {
                    errors++;
                    console.error('FluxFiles: Cross-disk ' + this.crossDiskMode + ' failed', file.key, err);
                    this.showToast(err.message || this.t('error.generic'), 'error', 4000);
                }
                this.tickBulk();
            }

            this.endBulk();
            if (errors === 0) {
                this.showToast(this.t('common.success'), 'success');
            }
            this.selected = [];
            this.detailFile = null;
            this.loadFiles();
        },

        cancelCrossDisk() {
            this.showCrossDisk = false;
        },

        // Create folder
        createFolder() {
            this.newFolderName = '';
            this.newFolderError = '';
            this.showNewFolder = true;
            this.$nextTick(() => {
                const input = this.$refs.newFolderInput;
                if (input) input.focus();
            });
        },

        closeNewFolder() {
            this.showNewFolder = false;
            this.newFolderName = '';
            this.newFolderError = '';
            this.newFolderCreating = false;
        },

        async submitNewFolder() {
            const name = (this.newFolderName || '').trim();
            this.newFolderError = '';

            if (!name) {
                this.newFolderError = this.t('folder.name_required') || 'Please enter a folder name';
                return;
            }
            if (/[<>:"/\\|?*]/.test(name)) {
                this.newFolderError = this.t('folder.invalid_chars') || 'Folder name contains invalid characters';
                return;
            }

            this.newFolderCreating = true;
            try {
                await this.api('POST', '/api/fm/mkdir', {
                    disk: this.currentDisk,
                    path: (this.currentPath ? this.currentPath + '/' : '') + name
                });
                this.postMessage('FM_EVENT', { event: 'folder:created', name: name });
                this.loadFiles();
                this.closeNewFolder();
            } catch (err) {
                console.error('FluxFiles: Create folder failed', err);
                this.newFolderError = err.message || 'Failed to create folder';
            } finally {
                this.newFolderCreating = false;
            }
        },

        // Rename
        openRename(item) {
            if (!item) return;
            this.renameTarget = item;
            // Files: split off the extension and lock it (only the base is editable).
            // Folders / extensionless files: the whole name is editable.
            const dotIdx = item.type !== 'dir' ? item.name.lastIndexOf('.') : -1;
            if (dotIdx > 0) {
                this.renameValue = item.name.slice(0, dotIdx);
                this.renameExt = item.name.slice(dotIdx); // includes the dot
            } else {
                this.renameValue = item.name;
                this.renameExt = '';
            }
            this.renameError = '';
            this.showRename = true;
            this.$nextTick(() => {
                const input = this.$refs.renameInput;
                if (input) {
                    input.focus();
                    input.select(); // base name only — extension is shown locked beside it
                }
            });
        },

        closeRename() {
            this.showRename = false;
            this.renameTarget = null;
            this.renameValue = '';
            this.renameExt = '';
            this.renameError = '';
            this.renameSubmitting = false;
        },

        async submitRename() {
            const base = (this.renameValue || '').trim();
            this.renameError = '';

            if (!base) {
                this.renameError = this.t('rename.error_empty');
                return;
            }
            // Re-attach the locked extension; the user only edits the base name.
            const name = base + this.renameExt;
            if (/[<>:"/\\|?*]/.test(base)) {
                this.renameError = this.t('rename.error_chars');
                return;
            }
            if (name === this.renameTarget.name) {
                this.closeRename();
                return;
            }

            this.renameSubmitting = true;
            try {
                const result = await this.api('POST', '/api/fm/rename', {
                    disk: this.currentDisk,
                    path: this.renameTarget.key,
                    name: name
                });
                this.postMessage('FM_EVENT', { event: 'rename:done', key: result.key, oldKey: this.renameTarget.key, name: name });
                this.showToast(this.t('rename.success'), 'success');
                if (this.detailFile && this.detailFile.key === this.renameTarget.key) {
                    this.detailFile = null;
                }
                this.selected = [];
                this.loadFiles();
                this.closeRename();
            } catch (err) {
                console.error('FluxFiles: Rename failed', err);
                this.renameError = err.message || this.t('error.generic');
            } finally {
                this.renameSubmitting = false;
            }
        },

        // Copy URL
        async copyUrl(file) {
            // Build full URL: use active variant if selected
            let url = this.getActiveUrl(file) || '';
            if (url && !url.startsWith('http')) {
                const base = this.endpoint || window.location.origin;
                url = base.replace(/\/$/, '') + '/' + url.replace(/^\//, '');
            }
            try {
                await navigator.clipboard.writeText(url);
                this.showToast(this.t('copy.copied'), 'success');
            } catch {
                // Fallback
                const ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                this.showToast(this.t('copy.copied'), 'success');
            }
        },

        // Download
        downloadFile(file) {
            const a = document.createElement('a');
            a.href = file.url;
            a.download = file.name;
            a.target = '_blank';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        },

        // Metadata
        watchMeta() {
            clearTimeout(this.metaSaveTimer);
            this.metaSaveTimer = setTimeout(() => this.saveMeta(), 800);
        },

        async saveMeta() {
            if (!this.detailFile) return;
            this.metaSaving = true;

            try {
                await this.api('PUT', '/api/fm/metadata', {
                    disk: this.currentDisk,
                    key: this.detailFile.key,
                    ...this.metaForm
                });

                // Update local state
                this.detailFile.meta = { ...this.metaForm };
                const idx = this.files.findIndex(f => f.key === this.detailFile.key);
                if (idx >= 0) {
                    this.files[idx].meta = { ...this.metaForm };
                }
            } catch (err) {
                console.error('FluxFiles: Save metadata failed', err);
                this.showToast(err.message || this.t('error.generic'), 'error', 4000);
            } finally {
                this.metaSaving = false;
            }
        },

        hasMetaBadge(file) {
            return file.meta != null;
        },

        // AI Tag
        async aiTag() {
            if (!this.detailFile) return;
            this.aiTagging = true;
            try {
                const result = await this.api('POST', '/api/fm/ai-tag', {
                    disk: this.currentDisk,
                    path: this.detailFile.key
                });
                if (result && result.tags) {
                    this.aiTags = result.tags;
                    if (result.title && !this.metaForm.title) this.metaForm.title = result.title;
                    if (result.alt_text && !this.metaForm.alt_text) this.metaForm.alt_text = result.alt_text;
                    if (result.caption && !this.metaForm.caption) this.metaForm.caption = result.caption;

                    this.detailFile.meta = {
                        ...this.metaForm,
                        tags: result.tags.join(', ')
                    };

                    const idx = this.files.findIndex(f => f.key === this.detailFile.key);
                    if (idx >= 0) {
                        this.files[idx].meta = { ...this.detailFile.meta };
                    }
                }
                this.postMessage('FM_EVENT', {
                    event: 'ai_tag:done',
                    key: this.detailFile.key,
                    tags: result.tags || []
                });
            } catch (err) {
                console.error('FluxFiles: AI tag failed', err);
                this.showToast(this.t('ai.failed', { message: err.message }) || ('AI tagging failed: ' + err.message), 'error', 4000);
            } finally {
                this.aiTagging = false;
            }
        },

        removeTag(index) {
            this.aiTags.splice(index, 1);
            if (this.detailFile) {
                const tagsStr = this.aiTags.join(', ');
                this.detailFile.meta = { ...this.metaForm, tags: tagsStr };
                // Save updated tags
                this.api('PUT', '/api/fm/metadata', {
                    disk: this.currentDisk,
                    key: this.detailFile.key,
                    ...this.metaForm,
                    tags: tagsStr
                }).catch(err => console.error('FluxFiles: Save tags failed', err));
            }
        },

        // Crop
        initCrop() {
            this.cropActive = true;
            this.cropData = { x: 0, y: 0, w: 0, h: 0 };
            this.cropAspect = null;

            this.$nextTick(() => {
                const img = this.$refs.cropImage;
                if (!img) return;

                const load = () => {
                    this._cropNatW = img.naturalWidth;
                    this._cropNatH = img.naturalHeight;
                    // Default selection: center 80%
                    const margin = 0.1;
                    this.cropData = {
                        x: Math.round(this._cropNatW * margin),
                        y: Math.round(this._cropNatH * margin),
                        w: Math.round(this._cropNatW * (1 - margin * 2)),
                        h: Math.round(this._cropNatH * (1 - margin * 2))
                    };
                };

                if (img.complete) load();
                else img.onload = load;
            });
        },

        cancelCrop() {
            this.cropActive = false;
        },

        // Convert crop selection from natural coords to display % for the overlay
        get cropStyle() {
            if (!this._cropNatW || !this._cropNatH) return {};
            const d = this.cropData;
            return {
                left: (d.x / this._cropNatW * 100) + '%',
                top: (d.y / this._cropNatH * 100) + '%',
                width: (d.w / this._cropNatW * 100) + '%',
                height: (d.h / this._cropNatH * 100) + '%'
            };
        },

        cropMouseDown(e) {
            const container = this.$refs.cropContainer;
            if (!container) return;
            this._cropImgRect = container.getBoundingClientRect();

            const relX = (e.clientX - this._cropImgRect.left) / this._cropImgRect.width;
            const relY = (e.clientY - this._cropImgRect.top) / this._cropImgRect.height;

            this._cropStart = {
                x: Math.round(relX * this._cropNatW),
                y: Math.round(relY * this._cropNatH)
            };
            this._cropDragging = true;
            this.cropData = { x: this._cropStart.x, y: this._cropStart.y, w: 0, h: 0 };

            e.preventDefault();
        },

        cropMouseMove(e) {
            if (!this._cropDragging || !this._cropImgRect) return;

            const rect = this._cropImgRect;
            const relX = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            const relY = Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height));

            let curX = Math.round(relX * this._cropNatW);
            let curY = Math.round(relY * this._cropNatH);

            let x = Math.min(this._cropStart.x, curX);
            let y = Math.min(this._cropStart.y, curY);
            let w = Math.abs(curX - this._cropStart.x);
            let h = Math.abs(curY - this._cropStart.y);

            // Apply aspect ratio constraint
            if (this.cropAspect) {
                const [aw, ah] = this.cropAspect.split(':').map(Number);
                const ratio = aw / ah;
                const newH = Math.round(w / ratio);
                if (newH + y <= this._cropNatH) {
                    h = newH;
                } else {
                    h = this._cropNatH - y;
                    w = Math.round(h * ratio);
                }
            }

            // Clamp
            w = Math.min(w, this._cropNatW - x);
            h = Math.min(h, this._cropNatH - y);

            this.cropData = { x, y, w, h };
            e.preventDefault();
        },

        cropMouseUp() {
            this._cropDragging = false;
        },

        setCropAspect(aspect) {
            this.cropAspect = aspect;
            // Re-apply to current selection
            if (aspect && this.cropData.w > 0) {
                const [aw, ah] = aspect.split(':').map(Number);
                const ratio = aw / ah;
                let w = this.cropData.w;
                let h = Math.round(w / ratio);
                if (h + this.cropData.y > this._cropNatH) {
                    h = this._cropNatH - this.cropData.y;
                    w = Math.round(h * ratio);
                }
                this.cropData.w = Math.min(w, this._cropNatW - this.cropData.x);
                this.cropData.h = Math.min(h, this._cropNatH - this.cropData.y);
            }
        },

        get cropInfo() {
            const d = this.cropData;
            if (d.w <= 0 || d.h <= 0) return '';
            return d.w + ' x ' + d.h + 'px';
        },

        async saveCrop(mode) {
            const d = this.cropData;
            if (d.w <= 0 || d.h <= 0) return;

            this.cropSaving = true;
            try {
                const body = {
                    disk: this.currentDisk,
                    path: this.detailFile.key,
                    x: d.x,
                    y: d.y,
                    width: d.w,
                    height: d.h
                };

                // 'replace' overwrites, 'copy' saves as new file
                if (mode === 'copy') {
                    const ext = this.detailFile.name.split('.').pop();
                    const base = this.detailFile.name.replace(/\.[^.]+$/, '');
                    body.save_path = (this.currentPath ? this.currentPath + '/' : '') + base + '_cropped.' + ext;
                }

                const result = await this.api('POST', '/api/fm/crop', body);

                this.postMessage('FM_EVENT', {
                    event: 'crop:done',
                    key: result.key,
                    width: result.width,
                    height: result.height
                });

                this.cropActive = false;
                this.loadFiles();
            } catch (err) {
                console.error('FluxFiles: Crop failed', err);
                this.showToast(this.t('crop.failed', { message: err.message }) || ('Crop failed: ' + err.message), 'error', 4000);
            } finally {
                this.cropSaving = false;
            }
        },

        // Commands from parent
        handleCommand(payload) {
            switch (payload.action) {
                case 'navigate':
                    this.navigate(payload.path || '');
                    break;
                case 'setDisk':
                    this.switchDisk(payload.disk || 'local');
                    break;
                case 'refresh':
                    this.loadFiles();
                    break;
                case 'search':
                    this.searchQuery = payload.q || '';
                    this._scheduleSearch();
                    break;
                case 'crossCopy':
                    this.crossDiskTarget = payload.dst_disk || '';
                    this.crossDiskPath = payload.dst_path || this.currentPath;
                    this.crossDiskMode = 'copy';
                    if (this.selected.length > 0 && this.crossDiskTarget) {
                        this.executeCrossDisk();
                    }
                    break;
                case 'crossMove':
                    this.crossDiskTarget = payload.dst_disk || '';
                    this.crossDiskPath = payload.dst_path || this.currentPath;
                    this.crossDiskMode = 'move';
                    if (this.selected.length > 0 && this.crossDiskTarget) {
                        this.executeCrossDisk();
                    }
                    break;
                case 'setLocale':
                    if (payload.locale) {
                        this.switchLocale(payload.locale);
                    }
                    break;
                case 'aiTag':
                    if (this.detailFile) {
                        this.aiTag();
                    }
                    break;
                case 'close':
                    this.closeManager();
                    break;
            }
        },

        // Theme
        applyTheme(theme) {
            this.theme = theme || 'auto';
            this._updateThemeClass();
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem('fluxfiles_theme', this.theme);
            }
        },

        toggleTheme() {
            const root = document.documentElement;
            const isDark = root.classList.contains('dark');
            this.applyTheme(isDark ? 'light' : 'dark');
        },

        _updateThemeClass() {
            const root = document.documentElement;
            let isDark = false;
            if (this.theme === 'dark') {
                isDark = true;
            } else if (this.theme === 'light') {
                isDark = false;
            } else {
                // auto — system preference
                isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            }
            this.isDark = isDark;
            if (isDark) {
                root.classList.add('dark');
            } else {
                root.classList.remove('dark');
            }
        },

        _initTheme() {
            const stored = typeof localStorage !== 'undefined' ? localStorage.getItem('fluxfiles_theme') : null;
            // An explicit 'dark'/'light' from the host (URL ?theme= or FM_CONFIG) is a
            // deliberate override and wins so the embed matches the host app. A null or
            // 'auto' config means "no opinion" — fall back to the user's saved choice,
            // then to system preference. (Override is not persisted to localStorage;
            // only toggleTheme/user action writes it, so the host can stop forcing later.)
            const configTheme = this.config.theme;
            const override = (configTheme === 'dark' || configTheme === 'light') ? configTheme : null;
            this.theme = override || stored || 'auto';
            this._updateThemeClass();

            // Listen to system preference when theme is 'auto'
            if (this._themeMediaQuery) {
                this._themeMediaQuery.removeEventListener('change', this._themeMediaHandler);
                this._themeMediaQuery = null;
            }
            if (this.theme === 'auto' && window.matchMedia) {
                const mq = window.matchMedia('(prefers-color-scheme: dark)');
                this._themeMediaHandler = () => { this._updateThemeClass(); };
                mq.addEventListener('change', this._themeMediaHandler);
                this._themeMediaQuery = mq;
            }
        },

        // Close
        closeManager() {
            this.postMessage('FM_CLOSE', {});
        },

        // Watch search query changes
        _initSearchWatcher() {
            // Alpine doesn't provide deep watchers here; poll via microtask on input binding changes.
            // We keep it simple: run schedule on next tick in places that mutate searchQuery.
        },

        // Utility
        // Activity log (audit) — decode the JWT payload client-side (cosmetic only;
        // the server enforces the 'audit' permission) to know whether to show it.
        _tokenPayload() {
            try {
                const seg = (this.token || '').split('.')[1] || '';
                const b64 = seg.replace(/-/g, '+').replace(/_/g, '/');
                const pad = b64.length % 4 ? '='.repeat(4 - (b64.length % 4)) : '';
                return JSON.parse(atob(b64 + pad)) || {};
            } catch (_) {
                return {};
            }
        },

        get canAudit() {
            const perms = this._tokenPayload().perms;
            return Array.isArray(perms) && perms.includes('audit');
        },

        // Inline video/audio preview is on unless the token disables it.
        get mediaPreviewEnabled() {
            const v = this._tokenPayload().media_preview;
            return v === undefined ? true : !!v;
        },

        // TTL (seconds) used when re-presigning an expiring media URL. Falls back
        // to 2h when the token doesn't set preview_url_ttl.
        get previewUrlTtl() {
            const t = parseInt(this._tokenPayload().preview_url_ttl, 10);
            return (t && t > 0) ? t : 7200;
        },

        // Max file size (MB) eligible for inline media preview; larger files show
        // a download placeholder instead. Falls back to 500MB.
        get maxPreviewMb() {
            const m = parseInt(this._tokenPayload().max_preview_mb, 10);
            return (m && m > 0) ? m : 500;
        },

        // True when a media file is too big to preview inline (size known + over cap).
        mediaTooLarge(file) {
            if (!file || !file.size) return false;
            return file.size > this.maxPreviewMb * 1024 * 1024;
        },

        async openActivity() {
            this.showActivity = true;
            this.activityError = '';
            await this.loadActivity();
        },

        async loadActivity() {
            this.activityLoading = true;
            this.activityError = '';
            try {
                const q = new URLSearchParams({ limit: '200' });
                const f = this.activityFilter;
                if (f.action) q.set('action', f.action);
                if (f.path) q.set('path', f.path);
                if (f.from) { const t = Date.parse(f.from); if (!isNaN(t)) q.set('from', String(Math.floor(t / 1000))); }
                if (f.to) { const t = Date.parse(f.to); if (!isNaN(t)) q.set('to', String(Math.floor(t / 1000))); }
                const data = await this.api('GET', '/api/fm/audit?' + q.toString());
                this.activityEntries = Array.isArray(data) ? data : [];
            } catch (e) {
                this.activityError = e.message || this.t('error.generic');
                this.activityEntries = [];
            } finally {
                this.activityLoading = false;
            }
        },

        closeActivity() {
            this.showActivity = false;
        },

        // Bucket Doctor — diagnose the current disk's backend (write perm gated
        // server-side; the button is shown when the token can write).
        get canDiagnose() {
            const perms = this._tokenPayload().perms;
            return Array.isArray(perms) && perms.includes('write');
        },

        async openDoctor() {
            this.showDoctor = true;
            this.doctorReport = null;
            this.doctorError = '';
            await this.loadDoctor();
        },

        async loadDoctor() {
            this.doctorLoading = true;
            this.doctorError = '';
            try {
                const data = await this.api('GET', '/api/fm/disk/doctor?disk=' + encodeURIComponent(this.currentDisk));
                this.doctorReport = data || null;
            } catch (e) {
                this.doctorError = e.message || this.t('error.generic');
                this.doctorReport = null;
            } finally {
                this.doctorLoading = false;
            }
        },

        closeDoctor() {
            this.showDoctor = false;
        },

        async _copyText(text) {
            try {
                await navigator.clipboard.writeText(text);
            } catch (_) {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            this.showToast(this.t('copy.copied'), 'success');
        },

        // Trash (soft-delete) — gated by the 'delete' permission.
        get canManageTrash() {
            const perms = this._tokenPayload().perms;
            return Array.isArray(perms) && perms.includes('delete');
        },

        // ── Import from URL ─────────────────────────────────────────────────
        get canImport() {
            return !!this._tokenPayload().allow_url_import;
        },

        openUrlImport() {
            this.urlImportUrl = '';
            this.urlImportError = '';
            this.urlImportResult = null;
            this.urlImportState = 'input';
            this.showUrlImport = true;
            this.$nextTick(() => { try { this.$refs.urlImportInput?.focus(); } catch (e) {} });
        },
        closeUrlImport() { this.showUrlImport = false; },

        async importUrl() {
            const url = (this.urlImportUrl || '').trim();
            if (!url || this.urlImportState === 'importing') return;
            this.urlImportState = 'importing';
            this.urlImportError = '';
            try {
                const res = await this.api('POST', '/api/fm/import-url', {
                    disk: this.currentDisk,
                    path: this.currentPath,
                    url: url
                });
                this.urlImportResult = res;
                this.urlImportState = 'success';
                this.showToast(this.t('import.success'), 'success');
                this.postMessage('FM_EVENT', { event: 'import:done', key: res && res.key });
                this.loadFiles();
                this.loadQuota();
            } catch (err) {
                this.urlImportState = 'error';
                this.urlImportError = err.message || this.t('error.import_failed');
            }
        },

        async openTrash() {
            this.showTrash = true;
            this.trashError = '';
            await this.loadTrash();
        },

        async loadTrash() {
            this.trashLoading = true;
            this.trashError = '';
            try {
                const data = await this.api('GET', '/api/fm/trash/list?disk=' + encodeURIComponent(this.currentDisk));
                this.trashEntries = Array.isArray(data) ? data : [];
            } catch (e) {
                this.trashError = e.message || this.t('error.generic');
                this.trashEntries = [];
            } finally {
                this.trashLoading = false;
            }
        },

        closeTrash() {
            this.showTrash = false;
        },

        async restoreItem(id) {
            try {
                const r = await this.api('POST', '/api/fm/trash/restore', { disk: this.currentDisk, trash_id: id });
                this.showToast(this.t('trash.restored'), 'success');
                this.postMessage('FM_EVENT', { event: 'restore:done', key: r && r.key });
                await this.loadTrash();
                this.loadFiles();
            } catch (e) {
                this.showToast(e.message || this.t('error.generic'), 'error', 4000);
            }
        },

        async purgeItem(id) {
            try {
                await this.api('POST', '/api/fm/trash/purge', { disk: this.currentDisk, trash_id: id });
                this.showToast(this.t('trash.purged'), 'success');
                await this.loadTrash();
            } catch (e) {
                this.showToast(e.message || this.t('error.generic'), 'error', 4000);
            }
        },

        async emptyTrashAll() {
            try {
                await this.api('POST', '/api/fm/trash/empty', { disk: this.currentDisk });
                this.showToast(this.t('trash.emptied'), 'success');
                await this.loadTrash();
            } catch (e) {
                this.showToast(e.message || this.t('error.generic'), 'error', 4000);
            }
        },

        formatSize(bytes) {
            if (!bytes) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            let size = bytes;
            while (size >= 1024 && i < units.length - 1) {
                size /= 1024;
                i++;
            }
            return size.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
        },

        formatDate(ts) {
            if (!ts) return '';
            const d = new Date(ts * 1000);
            return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        fileIcon(file) {
            if (file.type === 'dir') return 'folder';
            const ext = (file.name || '').split('.').pop()?.toLowerCase();
            const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
            const videoExts = ['mp4', 'webm', 'mov', 'avi'];
            const audioExts = ['mp3', 'wav', 'ogg', 'flac'];
            const docExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

            if (imageExts.includes(ext)) return 'image';
            if (videoExts.includes(ext)) return 'video';
            if (audioExts.includes(ext)) return 'audio';
            if (docExts.includes(ext)) return 'document';
            return 'file';
        },

        /** @param {unknown} v */
        _searchableMetaField(v) {
            if (v == null || v === '') return '';
            return typeof v === 'string' ? v : String(v);
        },

        // Does a file match the current type filter?
        _matchTypeFilter(file) {
            if (!this.typeFilter || this.typeFilter === 'all') return true;
            const kind = this.fileIcon(file); // 'image' | 'video' | 'audio' | 'document' | 'file'
            if (this.typeFilter === 'other') return kind === 'file';
            return kind === this.typeFilter;
        },

        // Comparator based on sortBy / sortDir
        _compareItems(a, b) {
            const dir = this.sortDir === 'desc' ? -1 : 1;
            let av, bv;
            switch (this.sortBy) {
                case 'date':
                    av = Number(a.created || a.modified || 0);
                    bv = Number(b.created || b.modified || 0);
                    break;
                case 'size':
                    av = Number(a.size || 0);
                    bv = Number(b.size || 0);
                    break;
                case 'type':
                    av = this.fileIcon(a) || '';
                    bv = this.fileIcon(b) || '';
                    break;
                case 'name':
                default:
                    av = String(a.name || '').toLowerCase();
                    bv = String(b.name || '').toLowerCase();
                    return av.localeCompare(bv, undefined, { numeric: true }) * dir;
            }
            if (av < bv) return -1 * dir;
            if (av > bv) return 1 * dir;
            // Stable tie-break by name
            const an = String(a.name || '').toLowerCase();
            const bn = String(b.name || '').toLowerCase();
            return an.localeCompare(bn, undefined, { numeric: true });
        },

        get filteredFiles() {
            const q = (this.searchQuery || '').toLowerCase();
            const arr = this.files.filter(f => {
                if (!this._matchTypeFilter(f)) return false;
                if (!q) return true;
                const name = (f.name != null && f.name !== '') ? String(f.name) : '';
                if (name.toLowerCase().includes(q)) return true;
                if (f.key && String(f.key).toLowerCase().includes(q)) return true;
                if (f.meta) {
                    const m = f.meta;
                    const fields = [
                        this._searchableMetaField(m.title),
                        this._searchableMetaField(m.alt_text),
                        this._searchableMetaField(m.caption),
                        this._searchableMetaField(m.tags),
                    ];
                    for (const s of fields) {
                        if (s && s.toLowerCase().includes(q)) return true;
                    }
                }
                return false;
            });
            return arr.slice().sort((a, b) => this._compareItems(a, b));
        },

        isPreviewable(file, type) {
            if (!file || !file.name || file.type === 'dir') return false;
            // Tenant can disable inline media preview via the media_preview claim.
            if ((type === 'video' || type === 'audio') && !this.mediaPreviewEnabled) return false;
            const ext = file.name.split('.').pop()?.toLowerCase();
            const map = {
                image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'],
                video: ['mp4', 'webm', 'mov'],
                audio: ['mp3', 'wav', 'ogg', 'flac', 'aac'],
                pdf: ['pdf']
            };
            return (map[type] || []).includes(ext);
        },

        /**
         * Silently re-presign an expiring media URL when a <video>/<audio>
         * element errors mid-playback. S3/R2 GET URLs are presigned and expire
         * (default ~1h), so a long video — or one paused past expiry — would 403
         * and stop. We re-fetch a fresh URL via /presign, swap it in, and restore
         * the playhead + play state so the swap is seamless. Local/static URLs
         * never expire, so we no-op on them; a small retry cap stops looping on a
         * genuinely unplayable file.
         */
        async refreshMediaSrc(file, el) {
            if (!file || !el) return;
            const src = el.currentSrc || el.src || '';
            // Only expiring presigned URLs are worth refreshing.
            if (!/[?&](X-Amz-|Signature=)/.test(src)) return;
            if (el._ffRefreshing) return;
            el._ffRefreshTries = (el._ffRefreshTries || 0) + 1;
            if (el._ffRefreshTries > 2) return;   // likely unplayable — stop retrying
            el._ffRefreshing = true;

            const at = el.currentTime || 0;
            const wasPlaying = !el.paused && !el.ended;
            try {
                const data = await this.api('POST', '/api/fm/presign', {
                    disk: this.currentDisk,
                    path: file.key,
                    method: 'GET',
                    ttl: this.previewUrlTtl,
                });
                if (!data || !data.url) return;

                // Restore the playhead once the fresh source has loaded.
                const restore = () => {
                    el.removeEventListener('loadedmetadata', restore);
                    try { if (at > 0) el.currentTime = at; } catch (e) { /* seek may be denied */ }
                    if (wasPlaying) { el.play().catch(() => {}); }
                };
                el.addEventListener('loadedmetadata', restore);

                // Update the model; Alpine re-binds :src on the same element in place.
                if (file === this.detailFile) { this.detailFile.url = data.url; }
                else { file.url = data.url; }
            } catch (e) {
                // Silent: leave the native error UI; the user can re-open the file.
            } finally {
                el._ffRefreshing = false;
            }
        },

        get filteredFolders() {
            // Folders are always listed regardless of type filter; we only apply search filter + sort.
            const q = (this.searchQuery || '').toLowerCase();
            const arr = q ? this.folders.filter(f => {
                const name = (f.name != null && f.name !== '') ? String(f.name) : '';
                if (name.toLowerCase().includes(q)) return true;
                if (f.key && String(f.key).toLowerCase().includes(q)) return true;
                return false;
            }) : this.folders.slice();
            // For folders, 'size'/'type' sort falls back to name (folders have no size/type).
            const effectiveBy = (this.sortBy === 'size' || this.sortBy === 'type') ? 'name' : this.sortBy;
            const dir = this.sortDir === 'desc' ? -1 : 1;
            return arr.sort((a, b) => {
                if (effectiveBy === 'date') {
                    const av = Number(a.created || a.modified || 0);
                    const bv = Number(b.created || b.modified || 0);
                    if (av !== bv) return (av < bv ? -1 : 1) * dir;
                }
                const an = String(a.name || '').toLowerCase();
                const bn = String(b.name || '').toLowerCase();
                return an.localeCompare(bn, undefined, { numeric: true }) * dir;
            });
        },

        // Basename of a search row (file or folder) for name sorting.
        _searchName(row) {
            const raw = row.name || row.file_key || row.dir_key || row.key || '';
            return String(raw).split('/').pop().toLowerCase();
        },

        // Search results respect the active sort. File rows carry size/modified from
        // the index (core >= 0.2.9); rows from an older index without them fall back
        // to name. Folder search rows have no size/date, so they sort by name.
        get sortedSearchResults() {
            const rows = Array.isArray(this.searchResults) ? this.searchResults.slice() : [];
            const dir = this.sortDir === 'desc' ? -1 : 1;
            const by = this.sortBy;
            return rows.sort((a, b) => {
                if (by === 'date' || by === 'size') {
                    const val = (r) => by === 'date' ? Number(r.created || r.modified || 0) : Number(r.size || 0);
                    const av = val(a), bv = val(b);
                    if (av < bv) return -1 * dir;
                    if (av > bv) return 1 * dir;
                    return this._searchName(a).localeCompare(this._searchName(b), undefined, { numeric: true });
                }
                return this._searchName(a).localeCompare(this._searchName(b), undefined, { numeric: true }) * dir;
            });
        },

        get sortedSearchFolders() {
            const rows = Array.isArray(this.searchFolderResults) ? this.searchFolderResults.slice() : [];
            const dir = this.sortDir === 'desc' ? -1 : 1;
            return rows.sort((a, b) =>
                this._searchName(a).localeCompare(this._searchName(b), undefined, { numeric: true }) * dir);
        },

        // Toggle sort: if choosing same key, flip direction; otherwise set new key with asc.
        setSort(by) {
            const allowed = ['name','date','size','type'];
            if (!allowed.includes(by)) return;
            if (this.sortBy === by) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortBy = by;
                // Default to desc for date (newest first), asc for others
                this.sortDir = (by === 'date' || by === 'size') ? 'desc' : 'asc';
            }
            this.sortMenuOpen = false;
        },

        setTypeFilter(v) {
            const allowed = ['all','image','video','audio','document','other'];
            if (!allowed.includes(v)) return;
            this.typeFilter = v;
            this.filterMenuOpen = false;
        },

        // Human-readable label for the currently active sort (for the button)
        get sortLabel() {
            const map = {
                name: this.t('sort.name') || 'Name',
                date: this.t('sort.date') || 'Date',
                size: this.t('sort.size') || 'Size',
                type: this.t('sort.type') || 'Type'
            };
            return map[this.sortBy] || map.name;
        },

        get typeFilterLabel() {
            const map = {
                all: this.t('filter.all') || 'All',
                image: this.t('filter.image') || 'Images',
                video: this.t('filter.video') || 'Videos',
                audio: this.t('filter.audio') || 'Audio',
                document: this.t('filter.document') || 'Documents',
                other: this.t('filter.other') || 'Other'
            };
            return map[this.typeFilter] || map.all;
        },

        // Thumbnail CSS class (color-coded by file type)
        thumbClass(file) {
            const kind = this.fileIcon(file);
            const map = {
                image: 'thumb-img',
                video: 'thumb-vid',
                audio: 'thumb-audio',
                document: 'thumb-doc',
                folder: 'thumb-folder',
                file: 'thumb-file'
            };
            return map[kind] || 'thumb-file';
        },

        // Thumbnail emoji icon
        thumbIcon(file) {
            if (!file) return '📄';
            const kind = this.fileIcon(file);
            const map = {
                image: '🖼',
                video: '🎬',
                audio: '🎵',
                document: '📄',
                folder: '📁',
                file: '📝'
            };
            return map[kind] || '📄';
        },

        // Status bar text
        get statusText() {
            const fc = this.filteredFolders.length;
            const fi = this.filteredFiles.length;
            const total = fc + fi;
            const parts = [total + ' items'];
            if (fc > 0) parts.push(fc + ' folders');
            if (fi > 0) parts.push(fi + ' files');
            return parts.join(' · ');
        },

        // Quota
        quotaInfo: null,
        quotaPercent: 0,
        quotaLabel: '',

        // Usage dashboard
        showUsage: false,
        usageInfo: null,
        usageLoading: false,
        usageError: '',
        _usageRefreshAt: 0,

        async openUsage() {
            this.showUsage = true;
            await this.loadUsage();
        },
        closeUsage() { this.showUsage = false; },

        async loadUsage(refresh) {
            this.usageLoading = true;
            this.usageError = '';
            try {
                let path = '/api/fm/usage?disk=' + encodeURIComponent(this.currentDisk);
                if (refresh) { path += '&refresh=true'; }
                this.usageInfo = await this.api('GET', path);
            } catch (e) {
                this.usageError = e.message || 'Failed to load usage';
            } finally {
                this.usageLoading = false;
            }
        },

        // Refresh button: force a recompute, debounced to 60s (the server also
        // rate-limits ?refresh=true to 2/min as a backstop).
        async refreshUsage() {
            const now = Date.now();
            if (now - this._usageRefreshAt < 60000) { return; }
            this._usageRefreshAt = now;
            await this.loadUsage(true);
        },
        get usageRefreshReady() { return Date.now() - this._usageRefreshAt >= 60000; },

        // Quota status → meter colour class.
        get usageStatusClass() {
            const s = this.usageInfo && this.usageInfo.quota && this.usageInfo.quota.status;
            return s === 'critical' ? 'is-critical' : (s === 'warning' ? 'is-warning' : 'is-ok');
        },

        // Localised label for a type-group key (image/video/.../other).
        usageTypeLabel(type) {
            const k = this.t('usage.' + type);
            return k === 'usage.' + type ? type : k;
        },

        // Jump from a top-folder row into that folder in the manager.
        usageGoFolder(path) {
            this.closeUsage();
            this.navigate(path === '/' ? '' : path.replace(/^\//, ''));
        },

        // ── File permissions (chmod) — SFTP disks only ──────────────────────
        diskDriver: '',
        showChmod: false,
        chmodFile: null,
        chmodBits: 0,          // 0-511 (octal 000-777)
        chmodLoading: false,
        chmodError: '',

        // chmod is offered only on an SFTP disk and the token allows it.
        get canChmod() {
            return this.diskDriver === 'sftp' && this.tokenAllows('allow_chmod', true);
        },
        // Read a tri-state boolean claim from the token (default when unset).
        tokenAllows(claim, dflt) {
            const v = this._tokenPayload()[claim];
            return v === undefined ? dflt : !!v;
        },

        async openChmod(file) {
            if (!file) return;
            this.chmodFile = file;
            this.showChmod = true;
            this.chmodError = '';
            this.chmodLoading = true;
            try {
                const data = await this.api('GET',
                    '/api/fm/chmod?disk=' + encodeURIComponent(this.currentDisk) +
                    '&path=' + encodeURIComponent(file.key));
                this.chmodBits = parseInt(data.mode, 8) || 0;
            } catch (e) {
                this.chmodError = e.message || 'Failed to read permissions';
            } finally {
                this.chmodLoading = false;
            }
        },
        closeChmod() { this.showChmod = false; this.chmodFile = null; },

        // Toggle one rwx bit. who: 0=owner,1=group,2=world; perm: 4=r,2=w,1=x.
        chmodToggle(who, perm) {
            const mask = perm << ((2 - who) * 3);
            this.chmodBits ^= mask;
        },
        chmodHas(who, perm) {
            const mask = perm << ((2 - who) * 3);
            return (this.chmodBits & mask) !== 0;
        },
        get chmodOctal() {
            return ((this.chmodBits >> 6) & 7).toString() + ((this.chmodBits >> 3) & 7).toString() + (this.chmodBits & 7).toString();
        },

        async applyChmod() {
            if (!this.chmodFile) return;
            this.chmodLoading = true;
            this.chmodError = '';
            try {
                await this.api('POST', '/api/fm/chmod', {
                    disk: this.currentDisk, path: this.chmodFile.key, mode: this.chmodOctal,
                });
                this.showToast(this.t('chmod.applied') || 'Permissions updated');
                this.closeChmod();
            } catch (e) {
                this.chmodError = e.message || 'Failed to set permissions';
            } finally {
                this.chmodLoading = false;
            }
        },

        // ── Config / code editor — read/edit a file's text content ──────────
        showEditor: false,
        editorFile: null,
        editorContent: '',
        editorOriginal: '',
        editorLoading: false,
        editorSaving: false,
        editorError: '',
        _cm: null,          // CodeMirror instance (created lazily, then reused)
        _cmLoading: null,   // promise that resolves once CodeMirror is on the page

        // ── Zip download / extract ──────────────────────────────────────────
        zipBusy: false,

        // Zip download needs allow_zip and a downloadable token (allow_download).
        get canZip() {
            return this.tokenAllows('allow_zip', true) && this.tokenAllows('allow_download', true);
        },
        get canExtract() {
            return this.tokenAllows('allow_extract', true);
        },
        canExtractFile(file) {
            if (!file || file.type === 'dir') return false;
            const name = (file.name || file.key || '').toLowerCase();
            return this.canExtract && name.endsWith('.zip');
        },

        // Download a selection (files/folders) as a zip. POSTs to /api/fm/zip and
        // saves the streamed blob (an <a download> can't send the Bearer header).
        async downloadZip(paths, name) {
            paths = (paths || []).filter(Boolean);
            if (!paths.length || this.zipBusy) return;
            this.zipBusy = true;
            try {
                const res = await fetch(this.joinUrl('/api/fm/zip'), {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + this.token, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ disk: this.currentDisk, paths, name: name || null }),
                });
                if (!res.ok) {
                    let msg = this.t('zip.failed') || 'Could not create the zip';
                    try { const j = await res.json(); if (j && j.error) msg = j.error; } catch (e) { /* non-JSON */ }
                    this.showToast(msg, 'error');
                    return;
                }
                const blob = await res.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = ((name || 'files').replace(/\.zip$/i, '')) + '.zip';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 2000);
            } catch (e) {
                this.showToast(e.message || (this.t('zip.failed') || 'Zip failed'), 'error');
            } finally {
                this.zipBusy = false;
            }
        },

        // Extract a .zip in place; reloads the listing so the new folder appears.
        async extractZip(file) {
            if (!file || this.zipBusy) return;
            this.zipBusy = true;
            try {
                const data = await this.api('POST', '/api/fm/extract', { disk: this.currentDisk, path: file.key });
                this.showToast(this.t('zip.extracted', { n: data.extracted }) || ('Extracted ' + data.extracted + ' files'));
                this.loadFiles();
            } catch (e) {
                this.showToast(e.message || (this.t('zip.extract_failed') || 'Extract failed'), 'error');
            } finally {
                this.zipBusy = false;
            }
        },

        // The Edit button shows for a text file when the token allows code editing.
        // allow_code_edit defaults FALSE (editing config/executables is opt-in).
        get canEditContent() {
            return this.tokenAllows('allow_code_edit', false);
        },
        _fileExt(file) {
            const name = (file?.name || file?.key || '').toLowerCase();
            const i = name.lastIndexOf('.');
            // Dotfiles like ".env" / ".htaccess": treat the whole name as the ext.
            return i > 0 ? name.slice(i + 1) : (name.startsWith('.') ? name.slice(1) : '');
        },
        // Extensions we treat as text-editable (config + common code/markup).
        editorExtensions: [
            'txt','text','md','markdown','json','json5','yaml','yml','xml','svg',
            'html','htm','vue','css','scss','sass','less','js','mjs','cjs','jsx','ts','tsx',
            'php','phtml','phps','py','rb','pl','pm','lua','sh','bash','zsh','fish',
            'env','ini','conf','cfg','config','toml','properties','sql','log','csv','tsv',
            'htaccess','htpasswd','gitignore','gitattributes','dockerignore','dockerfile',
            'c','h','cpp','cc','hpp','cs','java','go','rs','swift','kt','kts','r','jl','tf','tfvars',
            'graphql','gql','editorconfig','babelrc','eslintrc','prettierrc','npmrc',
        ],
        isTextFile(file) {
            if (!file || file.type === 'dir') return false;
            const name = (file.name || file.key || '').toLowerCase();
            if (name === 'dockerfile' || name === 'makefile' || name === 'procfile') return true;
            return this.editorExtensions.includes(this._fileExt(file));
        },
        canEditFile(file) {
            return this.canEditContent && this.isTextFile(file);
        },

        // CodeMirror 5 mode-module name for an extension (null → plain text).
        _cmMode(ext) {
            const m = {
                js:'javascript', mjs:'javascript', cjs:'javascript', json:'application/json',
                json5:'javascript', ts:'text/typescript', tsx:'jsx', jsx:'jsx',
                php:'application/x-httpd-php', phtml:'application/x-httpd-php', phps:'application/x-httpd-php',
                py:'python', rb:'ruby', pl:'perl', pm:'perl', lua:'lua', go:'go', rs:'rust',
                java:'text/x-java', c:'text/x-csrc', h:'text/x-csrc', cpp:'text/x-c++src',
                cc:'text/x-c++src', hpp:'text/x-c++src', cs:'text/x-csharp', swift:'swift',
                kt:'text/x-kotlin', kts:'text/x-kotlin', r:'r',
                sh:'shell', bash:'shell', zsh:'shell', fish:'shell', env:'shell',
                conf:'shell', cfg:'shell', config:'shell', ini:'properties', properties:'properties',
                toml:'toml', css:'css', scss:'text/x-scss', sass:'text/x-sass', less:'text/x-less',
                html:'htmlmixed', htm:'htmlmixed', vue:'htmlmixed', xml:'xml', svg:'xml',
                md:'markdown', markdown:'markdown', yaml:'yaml', yml:'yaml', sql:'sql',
                dockerfile:'dockerfile', graphql:'application/graphql', gql:'application/graphql',
            };
            return m[ext] || null;
        },

        // Lazy-load CodeMirror 5 (core CSS/JS + the common modes) from CDN on first
        // edit, so it never weighs on initial page load. Failure is non-fatal: the
        // bound <textarea> is the fallback editor.
        _ensureCodeMirror() {
            if (window.CodeMirror) return Promise.resolve();
            if (this._cmLoading) return this._cmLoading;
            const base = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16';
            const css = (href) => new Promise((res) => {
                const l = document.createElement('link');
                l.rel = 'stylesheet'; l.href = href; l.onload = res; l.onerror = res;
                document.head.appendChild(l);
            });
            const js = (src) => new Promise((res, rej) => {
                const s = document.createElement('script');
                s.src = src; s.onload = res; s.onerror = rej;
                document.head.appendChild(s);
            });
            this._cmLoading = (async () => {
                await Promise.all([
                    css(base + '/codemirror.min.css'),
                    css(base + '/theme/material-darker.min.css'),
                ]);
                await js(base + '/codemirror.min.js');
                // The 'simple mode' addon must load before modes built on it
                // (rust, dockerfile) — otherwise they throw `defineSimpleMode is
                // not a function` at load. Non-fatal but noisy; load it first.
                try { await js(base + '/addon/mode/simple.min.js'); } catch (e) { /* skip */ }
                // Order matters: htmlmixed needs xml/javascript/css; php needs clike+htmlmixed.
                const modes = [
                    'xml','javascript','css','clike','htmlmixed','php','jsx','python','ruby',
                    'perl','shell','go','rust','lua','swift','sql','yaml','markdown','toml',
                    'properties','dockerfile','r',
                ];
                for (const mode of modes) {
                    try { await js(base + '/mode/' + mode + '/' + mode + '.min.js'); } catch (e) { /* skip */ }
                }
                return true;
            })();
            return this._cmLoading;
        },

        _mountCM() {
            const ta = document.getElementById('ff-editor-ta');
            if (!ta || !window.CodeMirror) return;   // textarea fallback already works
            const mode = this._cmMode(this._fileExt(this.editorFile));
            const dark = document.documentElement.classList.contains('dark');
            if (this._cm) {
                this._cm.setOption('mode', mode);
                this._cm.setOption('theme', dark ? 'material-darker' : 'default');
                this._cm.setValue(this.editorContent);
                this._cm.clearHistory();
                setTimeout(() => this._cm.refresh(), 0);
                return;
            }
            this._cm = window.CodeMirror.fromTextArea(ta, {
                mode, lineNumbers: true, lineWrapping: false, indentUnit: 2, tabSize: 2,
                theme: dark ? 'material-darker' : 'default',
            });
            this._cm.setValue(this.editorContent);
            this._cm.on('change', (cm) => { this.editorContent = cm.getValue(); });
            setTimeout(() => this._cm.refresh(), 0);
        },

        async openEditor(file) {
            if (!file || !this.canEditFile(file)) return;
            this.editorFile = file;
            this.showEditor = true;
            this.editorError = '';
            this.editorContent = '';
            this.editorOriginal = '';
            this.editorLoading = true;
            try {
                const data = await this.api('GET',
                    '/api/fm/content?disk=' + encodeURIComponent(this.currentDisk) +
                    '&path=' + encodeURIComponent(file.key));
                this.editorContent = data.content || '';
                this.editorOriginal = this.editorContent;
                this.editorLoading = false;
                await this._ensureCodeMirror();
                this.$nextTick(() => this._mountCM());
            } catch (e) {
                this.editorLoading = false;
                this.editorError = e.message || this.t('editor.load_failed') || 'Failed to open file';
            }
        },

        get editorDirty() { return this.editorContent !== this.editorOriginal; },

        closeEditor() {
            if (this.editorDirty &&
                !confirm(this.t('editor.discard_confirm') || 'Discard unsaved changes?')) return;
            this.showEditor = false;
            this.editorFile = null;
            this.editorError = '';
            if (this._cm) this._cm.setValue('');
        },

        async saveEditor() {
            if (!this.editorFile || this.editorSaving || !this.editorDirty) return;
            this.editorSaving = true;
            this.editorError = '';
            try {
                const data = await this.api('PUT', '/api/fm/content', {
                    disk: this.currentDisk, path: this.editorFile.key, content: this.editorContent,
                });
                this.editorOriginal = this.editorContent;
                if (this.detailFile && data && typeof data.size === 'number') {
                    this.detailFile.size = data.size;
                }
                this.showToast(this.t('editor.saved') || 'Saved');
            } catch (e) {
                this.editorError = e.message || this.t('editor.save_failed') || 'Save failed';
            } finally {
                this.editorSaving = false;
            }
        },

        async loadQuota() {
            try {
                const data = await this.api('GET',
                    '/api/fm/quota?disk=' + encodeURIComponent(this.currentDisk));
                if (data) {
                    this.quotaInfo = data;
                    const usedMb = data.used_mb || 0;
                    const maxMb = data.max_mb || 0;
                    if (maxMb > 0) {
                        this.quotaPercent = Math.min(100, Math.round((usedMb / maxMb) * 100));
                        if (maxMb >= 1024) {
                            this.quotaLabel = (usedMb / 1024).toFixed(1) + ' / ' + (maxMb / 1024).toFixed(0) + ' GB';
                        } else {
                            this.quotaLabel = Math.round(usedMb) + ' / ' + Math.round(maxMb) + ' MB';
                        }
                    } else {
                        this.quotaPercent = 0;
                        this.quotaLabel = this.formatSize((usedMb || 0) * 1024 * 1024) + ' used';
                    }
                }
            } catch (_) {
                // Quota not available, that's ok
            }
        }
    };
}
