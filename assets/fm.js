function fluxFilesApp() {
    return {
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

        // Upload state
        uploadProgress: 0,
        uploading: false,
        dragActive: false,

        // Metadata state
        metaForm: { title: '', alt_text: '', caption: '' },
        metaSaving: false,
        metaSaveTimer: null,

        // Init
        init() {
            window.addEventListener('message', (e) => {
                const msg = e.data;
                if (!msg || msg.source !== 'fluxfiles') return;

                if (msg.type === 'FM_CONFIG') {
                    this.token = msg.payload.token || '';
                    this.currentDisk = msg.payload.disk || 'local';
                    this.endpoint = msg.payload.endpoint || '';
                    this.config = msg.payload;
                    if (msg.payload.theme) {
                        this.applyTheme(msg.payload.theme);
                    }
                    this.loadFiles();
                }

                if (msg.type === 'FM_COMMAND') {
                    this.handleCommand(msg.payload);
                }
            });

            // Apply theme
            this.applyTheme('auto');

            // Notify parent we're ready
            this.postMessage('FM_READY', {
                version: '1.0.0',
                capabilities: ['list', 'upload', 'delete', 'move', 'copy', 'mkdir', 'presign', 'metadata', 'cross-copy', 'cross-move', 'bulk-ops']
            });
        },

        // PostMessage helper
        postMessage(type, payload) {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    source: 'fluxfiles',
                    type: type,
                    v: 1,
                    id: 'ff-' + Math.random().toString(36).substr(2, 9),
                    payload: payload || {}
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
            this.loadFiles();
        },

        navigateUp() {
            const parts = this.currentPath.split('/').filter(Boolean);
            parts.pop();
            this.navigate(parts.join('/'));
        },

        get breadcrumbs() {
            const parts = this.currentPath.split('/').filter(Boolean);
            const crumbs = [{ name: 'Root', path: '' }];
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
            this.loadFiles();
        },

        // File selection
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
            } else {
                this.metaForm = { title: '', alt_text: '', caption: '' };
            }

            // Notify parent
            this.postMessage('FM_SELECT', {
                url: file.url,
                key: file.key,
                name: file.name,
                size: file.size,
                disk: this.currentDisk,
                meta: file.meta || null
            });
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
                this.selectFile(file);
            }
        },

        isSelected(file) {
            return this.selected.some(s => s.key === file.key);
        },

        // Select all / deselect all
        selectAll() {
            this.selected = [...this.filteredFiles];
        },

        deselectAll() {
            this.selected = [];
            this.detailFile = null;
        },

        toggleSelectAll() {
            if (this.selected.length === this.filteredFiles.length) {
                this.deselectAll();
            } else {
                this.selectAll();
            }
        },

        get allSelected() {
            return this.filteredFiles.length > 0 && this.selected.length === this.filteredFiles.length;
        },

        // Shift+click range select
        shiftSelect(file, event) {
            if (event && event.shiftKey && this.selected.length > 0) {
                const allFiles = this.filteredFiles;
                const lastSelected = this.selected[this.selected.length - 1];
                const lastIdx = allFiles.findIndex(f => f.key === lastSelected.key);
                const currIdx = allFiles.findIndex(f => f.key === file.key);

                if (lastIdx >= 0 && currIdx >= 0) {
                    const start = Math.min(lastIdx, currIdx);
                    const end = Math.max(lastIdx, currIdx);
                    const range = allFiles.slice(start, end + 1);

                    // Merge with existing selection (no duplicates)
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
                }
                this.tickBulk();
            }

            this.endBulk();
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
                }
                this.tickBulk();
            }

            this.endBulk();
            this.loadTrash();
        },

        // Bulk purge from trash
        async bulkPurge(items) {
            const list = items || this.trashItems;
            if (list.length === 0) return;
            this.startBulk('Purging', list.length);

            for (const item of [...list]) {
                try {
                    await this.api('DELETE', '/api/fm/purge', {
                        disk: this.currentDisk,
                        path: item.file_key
                    });
                    this.postMessage('FM_EVENT', { event: 'purge:done', key: item.file_key });
                } catch (err) {
                    console.error('FluxFiles: Bulk purge failed', item.file_key, err);
                }
                this.tickBulk();
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
            this.confirmMessage = 'Delete ' + this.selected.length + ' item(s)? This cannot be undone.';
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
        async createFolder() {
            const name = prompt('Folder name:');
            if (!name) return;

            try {
                await this.api('POST', '/api/fm/mkdir', {
                    disk: this.currentDisk,
                    path: (this.currentPath ? this.currentPath + '/' : '') + name
                });
                this.postMessage('FM_EVENT', { event: 'folder:created', name: name });
                this.loadFiles();
            } catch (err) {
                console.error('FluxFiles: Create folder failed', err);
                alert('Failed to create folder: ' + err.message);
            }
        },

        // Copy URL
        async copyUrl(file) {
            try {
                await navigator.clipboard.writeText(file.url);
            } catch {
                // Fallback
                const ta = document.createElement('textarea');
                ta.value = file.url;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
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
                case 'close':
                    this.closeManager();
                    break;
            }
        },

        // Theme
        applyTheme(theme) {
            const root = document.documentElement;
            if (theme === 'dark') {
                root.classList.add('dark');
            } else if (theme === 'light') {
                root.classList.remove('dark');
            } else {
                // auto — detect system preference
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    root.classList.add('dark');
                } else {
                    root.classList.remove('dark');
                }
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
            if (!file || !file.name) return false;
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
        }
    };
}
