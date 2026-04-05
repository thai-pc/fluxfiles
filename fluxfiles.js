(function(root) {
    'use strict';

    var VERSION = 1;
    var SOURCE = 'fluxfiles';
    var iframe = null;
    var listeners = {};
    var config = {};
    var ready = false;
    var iframeOrigin = '';

    function uuid() {
        return 'ff-' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
    }

    function postToIframe(type, payload) {
        if (!iframe || !iframe.contentWindow) return;
        iframe.contentWindow.postMessage({
            source: SOURCE,
            type: type,
            v: VERSION,
            id: uuid(),
            payload: payload || {}
        }, iframeOrigin || '*');
    }

    function handleMessage(e) {
        if (iframeOrigin && e.origin !== iframeOrigin) return;
        var msg = e.data;
        if (!msg || msg.source !== SOURCE) return;

        switch (msg.type) {
            case 'FM_READY':
                ready = true;
                postToIframe('FM_CONFIG', {
                    disk: config.disk || 'local',
                    disks: config.disks || ['local'],
                    token: config.token || '',
                    path: config.path || '',
                    mode: config.mode || 'picker',
                    multiple: !!config.multiple,
                    allowedTypes: config.allowedTypes || null,
                    maxSize: config.maxSize || null,
                    endpoint: config.endpoint || '',
                    locale: config.locale || null,
                    theme: config.theme || null
                });
                emit('FM_READY', msg.payload);
                break;

            case 'FM_SELECT':
                if (typeof config.onSelect === 'function') {
                    config.onSelect(msg.payload);
                }
                emit('FM_SELECT', msg.payload);
                FluxFiles.close();
                break;

            case 'FM_EVENT':
                emit('FM_EVENT', msg.payload);
                break;

            case 'FM_CLOSE':
                if (typeof config.onClose === 'function') {
                    config.onClose();
                }
                emit('FM_CLOSE', msg.payload);
                break;
        }
    }

    function emit(type, data) {
        var cbs = listeners[type] || [];
        for (var i = 0; i < cbs.length; i++) {
            try { cbs[i](data); } catch(ex) { console.error('FluxFiles listener error:', ex); }
        }
    }

    var FluxFiles = {
        open: function(options) {
            config = options || {};
            var endpoint = (config.endpoint || '').replace(/\/+$/, '');
            var container = config.container
                ? document.querySelector(config.container)
                : document.body;

            // Derive origin from endpoint for postMessage validation
            try {
                var u = new URL(endpoint + '/public/index.html');
                iframeOrigin = u.origin;
            } catch (_) {
                iframeOrigin = window.location.origin;
            }

            // Clean up existing
            this.close();

            // Create iframe
            iframe = document.createElement('iframe');
            iframe.id = 'fluxfiles-iframe';
            iframe.src = endpoint + '/public/index.html';
            iframe.style.cssText = 'width:100%;height:100%;border:none;';
            iframe.setAttribute('allow', 'clipboard-write');

            if (!config.container) {
                // Modal overlay — UI scaled down 5% (icons, text, spacing)
                var overlay = document.createElement('div');
                overlay.id = 'fluxfiles-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';

                var modal = document.createElement('div');
                modal.id = 'fluxfiles-modal';
                modal.style.cssText = 'width:90vw;height:85vh;max-width:1200px;background:#f5f5f7;border-radius:10px;overflow:hidden;box-shadow:0 25px 50px rgba(0,0,0,0.22);display:flex;flex-direction:column;';

                // Header — macOS-style red close button (left) with × icon
                var header = document.createElement('div');
                header.style.cssText = 'flex-shrink:0;display:flex;align-items:center;justify-content:flex-start;padding:10px 12px;border-bottom:1px solid rgba(0,0,0,0.06);background:#f5f5f7;';
                var closeBtn = document.createElement('button');
                closeBtn.type = 'button';
                closeBtn.setAttribute('aria-label', 'Close');
                closeBtn.style.cssText = 'width:28px;height:28px;border:none;border-radius:6px;background:transparent;color:#6b7280;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:20px;line-height:1;font-weight:300;transition:color .15s,background .15s;flex-shrink:0;';
                closeBtn.textContent = '\u00D7';
                closeBtn.addEventListener('mouseenter', function() { closeBtn.style.background = '#e5e7eb'; closeBtn.style.color = '#374151'; });
                closeBtn.addEventListener('mouseleave', function() { closeBtn.style.background = 'transparent'; closeBtn.style.color = '#6b7280'; });
                closeBtn.addEventListener('click', function() { FluxFiles.close(); });
                header.appendChild(closeBtn);
                modal.appendChild(header);

                var body = document.createElement('div');
                body.style.cssText = 'flex:1;min-height:0;display:flex;flex-direction:column;';
                body.appendChild(iframe);
                modal.appendChild(body);
                overlay.appendChild(modal);
                document.body.appendChild(overlay);

                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) FluxFiles.close();
                });
            } else {
                container.appendChild(iframe);
            }

            window.addEventListener('message', handleMessage);
        },

        close: function() {
            ready = false;
            window.removeEventListener('message', handleMessage);

            var overlay = document.getElementById('fluxfiles-overlay');
            if (overlay) overlay.remove();

            var existing = document.getElementById('fluxfiles-iframe');
            if (existing) existing.remove();

            iframe = null;
        },

        command: function(action, data) {
            postToIframe('FM_COMMAND', Object.assign({ action: action }, data || {}));
        },

        navigate: function(path) { this.command('navigate', { path: path }); },
        setDisk: function(disk) { this.command('setDisk', { disk: disk }); },
        refresh: function() { this.command('refresh'); },
        search: function(q) { this.command('search', { q: q }); },
        crossCopy: function(dstDisk, dstPath) { this.command('crossCopy', { dst_disk: dstDisk, dst_path: dstPath || '' }); },
        crossMove: function(dstDisk, dstPath) { this.command('crossMove', { dst_disk: dstDisk, dst_path: dstPath || '' }); },
        aiTag: function() { this.command('aiTag'); },
        setLocale: function(locale) { this.command('setLocale', { locale: locale }); },

        on: function(type, cb) {
            if (!listeners[type]) listeners[type] = [];
            listeners[type].push(cb);
            return function() { FluxFiles.off(type, cb); };
        },

        off: function(type, cb) {
            if (!listeners[type]) return;
            listeners[type] = listeners[type].filter(function(fn) { return fn !== cb; });
        }
    };

    // UMD export
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = FluxFiles;
    } else if (typeof define === 'function' && define.amd) {
        define(function() { return FluxFiles; });
    } else {
        root.FluxFiles = FluxFiles;
    }
})(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : this);
