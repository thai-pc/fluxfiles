function fluxFilesApp() {
    const LOCALE = window.__FM_LOCALE__ || { locale: 'en', dir: 'ltr', messages: {} };

    return {
        // i18n
        locale: LOCALE.locale,
        direction: LOCALE.dir,
        _messages: LOCALE.messages,

        // State
        currentDisk: 'local',
        currentPath: '',
        files: [],
        folders: [],
        selected: [],
        view: 'grid',
        loading: false,
        token: '',
        endpoint: '',
        config: {},
        searchQuery: '',
        detailFile: null,
        activeTab: 'info',
        showConfirm: false,
        confirmAction: null,
        confirmMessage: '',
        trashItems: [],
        showTrash: false,

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

        // Auth state
        authRequired: false,

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

        // Mobile: sidebar drawer
        sidebarOpen: false,

        // Detail panel: resizable width (desktop), persisted
        detailPanelWidth: 350,
        _resizeDetail: null, // { startX, startW } when dragging

        // Preview lightbox
        previewFullscreen: false,

        // Upload state
        uploadProgress: 0,
        uploading: false,
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

        async switchLocale(newLocale) {
            if (newLocale === this.locale && Object.keys(this._messages).length > 1) return;
            try {
                const base = this.endpoint || window.location.origin;
                const res = await fetch(base + '/api/fm/lang/' + encodeURIComponent(newLocale));
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

        // Init
        init() {
            // Restore detail panel width from localStorage
            try {
                const w = parseInt(localStorage.getItem('fluxfiles_detail_width'), 10);
                if (w >= 280 && w <= 600) this.detailPanelWidth = w;
            } catch (_) {}

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

            window.addEventListener('message', (e) => {
                const msg = e.data;
                if (!msg || msg.source !== 'fluxfiles') return;

                if (msg.type === 'FM_CONFIG') {
                    this.token = msg.payload.token || '';
                    this.currentDisk = msg.payload.disk || 'local';
                    this.endpoint = msg.payload.endpoint || '';
                    this.config = msg.payload;
                    if (msg.payload.path !== undefined) this.currentPath = msg.payload.path || '';
                    this.authRequired = false;
                    this._initTheme();

                    // Handle locale from host app
                    const targetLocale = msg.payload.locale && msg.payload.locale !== 'auto'
                        ? msg.payload.locale
                        : (navigator.language?.split('-')[0] || 'en');
                    if (targetLocale !== this.locale) {
                        this.switchLocale(targetLocale);
                    }

                    this.loadFiles();
                    this.loadQuota();
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
                version: '1.20.0',
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
                    multiple: params.get('multiple') === '1' || params.get('multiple') === 'true'
                };
                this._initTheme();

                // Locale priority: ?locale= > server Content-Language > browser lang
                const urlLocale = params.get('locale');
                const initLocale = async () => {
                    if (urlLocale) {
                        await this.switchLocale(urlLocale);
                    } else {
                        // Fetch /api/fm/lang to detect server-configured locale from Content-Language header
                        try {
                            const res = await fetch(this.endpoint + '/api/fm/lang');
                            const serverLocale = res.headers.get('Content-Language');
                            if (serverLocale && serverLocale !== 'en') {
                                await this.switchLocale(serverLocale);
                            } else {
                                const browserLocale = navigator.language?.split('-')[0] || 'en';
                                await this.switchLocale(browserLocale);
                            }
                        } catch (_) {
                            await this.switchLocale(navigator.language?.split('-')[0] || 'en');
                        }
                    }
                    if (this.token) {
                        this.loadFiles();
                        this.loadQuota();
                    } else {
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
                }, '*');
            }
        },

        // API helper
        async api(method, path, body) {
            const url = this.endpoint + path;
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
            const json = await res.json();

            if (json.error) {
                throw new Error(json.error);
            }

            return json.data;
        },

        // Load files
        async loadFiles() {
            this.loading = true;
            try {
                const items = await this.api('GET',
                    '/api/fm/list?disk=' + encodeURIComponent(this.currentDisk) +
                    '&path=' + encodeURIComponent(this.currentPath)
                );

                this.folders = (items || []).filter(i => i.type === 'dir');
                this.files = (items || []).filter(i => i.type === 'file');
                this.selected = [];
                this.detailFile = null;
            } catch (err) {
                console.error('FluxFiles: Failed to load files', err);
            } finally {
                this.loading = false;
            }
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
                meta: item.meta || null,
                type: item.type || 'file',
                is_dir: item.type === 'dir'
            };
        },

        // File selection (single — from detail panel)
        selectFile(file) {
            this.detailFile = file;
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
                if (this.config.multiple) {
                    // Multiple mode: just select, show detail — user clicks "Select" to confirm
                    this.detailFile = file;
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
                } else {
                    this.selectFile(file);
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
            // Select folder and trigger delete confirm
            this.selected = [folder];
            this.confirmDelete();
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
                    console.error('FluxFiles: Bulk move failed', file.key, err);
                }
                this.tickBulk();
            }

            this.endBulk();
            this.selected = [];
            this.detailFile = null;
            this.loadFiles();
        },

        // Bulk download (sequential)
        async bulkDownload() {
            for (const file of this.selected) {
                this.downloadFile(file);
                // Small delay so browser doesn't block multiple downloads
                await new Promise(r => setTimeout(r, 300));
            }
        },

        // Upload

        async uploadFiles(fileList) {
            if (!fileList || fileList.length === 0) return;
            this.uploading = true;
            this.uploadProgress = 0;

            const total = fileList.length;
            let done = 0;

            for (const file of fileList) {
                try {
                    // Use chunk upload for files > 10MB on S3/R2 disks
                    if (file.size > 10 * 1024 * 1024 && this.currentDisk !== 'local') {
                        await this.chunkUpload(file, this.currentDisk, this.currentPath);
                    } else {
                        const formData = new FormData();
                        formData.append('disk', this.currentDisk);
                        formData.append('path', this.currentPath);
                        formData.append('file', file);

                        await this.api('POST', '/api/fm/upload', formData);
                    }
                    done++;
                    this.uploadProgress = Math.round((done / total) * 100);

                    this.postMessage('FM_EVENT', { event: 'upload:done', name: file.name });
                } catch (err) {
                    console.error('FluxFiles: Upload failed', file.name, err);
                }
            }

            this.uploading = false;
            this.uploadProgress = 0;
            this.loadFiles();
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
        async chunkUpload(file, disk, path) {
            const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB
            const MAX_CONCURRENT = 3;
            const key = (path ? path + '/' : '') + file.name;

            // Initiate multipart upload
            const initData = await this.api('POST', '/api/fm/chunk/init', { disk, path: key });
            const uploadId = initData.upload_id;
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
                this.uploadProgress = Math.round((completedParts / totalParts) * 100);

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

            for (const file of [...this.selected]) {
                try {
                    await this.api('DELETE', '/api/fm/delete', {
                        disk: this.currentDisk,
                        path: file.key
                    });
                    this.postMessage('FM_EVENT', { event: 'delete:done', key: file.key });
                } catch (err) {
                    console.error('FluxFiles: Delete failed', file.key, err);
                    this.showToast(err.message || this.t('error.generic'), 'error', 4000);
                }
                this.tickBulk();
            }

            this.endBulk();
            this.showToast(this.t('delete.deleted'), 'success');
            this.selected = [];
            this.detailFile = null;
            this.loadFiles();
        },

        // Restore from trash
        async restoreFile(fileKey) {
            try {
                await this.api('POST', '/api/fm/restore', {
                    disk: this.currentDisk,
                    path: fileKey
                });
                this.postMessage('FM_EVENT', { event: 'restore:done', key: fileKey });
                this.loadFiles();
            } catch (err) {
                console.error('FluxFiles: Restore failed', err);
                this.showToast(err.message || this.t('error.generic'), 'error', 4000);
            }
        },

        // Purge (permanent delete)
        async purgeFile(fileKey) {
            try {
                await this.api('DELETE', '/api/fm/purge', {
                    disk: this.currentDisk,
                    path: fileKey
                });
                this.postMessage('FM_EVENT', { event: 'purge:done', key: fileKey });
                this.loadTrash();
            } catch (err) {
                console.error('FluxFiles: Purge failed', err);
                this.showToast(err.message || this.t('error.generic'), 'error', 4000);
            }
        },

        // Bulk restore from trash
        async bulkRestore(items) {
            const list = items || this.trashItems;
            if (list.length === 0) return;
            this.startBulk('Restoring', list.length);

            for (const item of [...list]) {
                try {
                    await this.api('POST', '/api/fm/restore', {
                        disk: this.currentDisk,
                        path: item.file_key
                    });
                    this.postMessage('FM_EVENT', { event: 'restore:done', key: item.file_key });
                } catch (err) {
                    console.error('FluxFiles: Bulk restore failed', item.file_key, err);
                    this.showToast(err.message || this.t('error.generic'), 'error', 4000);
                }
                this.tickBulk();
            }

            this.endBulk();
            this.loadTrash();
        },

        // Bulk purge from trash (single API call to avoid 429 rate limit)
        async bulkPurge(items) {
            const list = items || this.trashItems;
            if (list.length === 0) return;
            this.startBulk('Purging', list.length);

            try {
                const paths = list.map(item => item.file_key);
                const result = await this.api('POST', '/api/fm/purge-bulk', {
                    disk: this.currentDisk,
                    paths
                });
                const purged = result?.purged || [];
                const errors = result?.errors || [];
                for (const key of purged) {
                    this.postMessage('FM_EVENT', { event: 'purge:done', key });
                }
                if (errors.length > 0) {
                    this.showToast(this.t('delete.purge_partial', { count: errors.length }) || (`${errors.length} item(s) failed to purge`), 'error', 4000);
                } else if (purged.length > 0) {
                    this.showToast(this.t('delete.purged'), 'success');
                }
            } catch (err) {
                console.error('FluxFiles: Bulk purge failed', err);
                this.showToast(err.message || this.t('error.generic'), 'error', 4000);
            }

            this.endBulk();
            this.loadTrash();
        },

        // Load trash items
        async loadTrash() {
            this.loading = true;
            try {
                const items = await this.api('GET',
                    '/api/fm/trash?disk=' + encodeURIComponent(this.currentDisk)
                );
                this.trashItems = items || [];
            } catch (err) {
                console.error('FluxFiles: Failed to load trash', err);
            } finally {
                this.loading = false;
            }
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
                    console.error('FluxFiles: Cross-disk ' + this.crossDiskMode + ' failed', file.key, err);
                }
                this.tickBulk();
            }

            this.endBulk();
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

        // Copy URL
        async copyUrl(file) {
            // Build full URL: if relative, prepend origin
            let url = file.url || '';
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
            const configTheme = this.config.theme;
            const theme = stored || configTheme || 'auto';
            this.theme = theme;
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

        // Utility
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

        get filteredFiles() {
            if (!this.searchQuery) return this.files;
            const q = this.searchQuery.toLowerCase();
            return this.files.filter(f => f.name.toLowerCase().includes(q));
        },

        isPreviewable(file, type) {
            if (!file || !file.name || file.type === 'dir') return false;
            const ext = file.name.split('.').pop()?.toLowerCase();
            const map = {
                image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'],
                video: ['mp4', 'webm', 'mov'],
                audio: ['mp3', 'wav', 'ogg', 'flac', 'aac'],
                pdf: ['pdf']
            };
            return (map[type] || []).includes(ext);
        },

        get filteredFolders() {
            if (!this.searchQuery) return this.folders;
            const q = this.searchQuery.toLowerCase();
            return this.folders.filter(f => f.name.toLowerCase().includes(q));
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
