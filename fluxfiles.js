(function(root) {
    'use strict';

    var VERSION = 1;
    var SOURCE = 'fluxfiles';
    var iframe = null;
    var listeners = {};
    var config = {};
    var ready = false;

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
        }, '*');
    }

    function handleMessage(e) {
        var msg = e.data;
        if (!msg || msg.source !== SOURCE) return;

        switch (msg.type) {
            case 'FM_READY':
                ready = true;
                postToIframe('FM_CONFIG', {
                    disk: config.disk || 'local',
                    token: config.token || '',
                    mode: config.mode || 'picker',
                    allowedTypes: config.allowedTypes || null,
                    maxSize: config.maxSize || null,
                    endpoint: config.endpoint || ''
                });
                emit('FM_READY', msg.payload);
                break;

            case 'FM_SELECT':
                if (typeof config.onSelect === 'function') {
                    config.onSelect(msg.payload);
                }
                emit('FM_SELECT', msg.payload);
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

            // Clean up existing
            this.close();

            // Create iframe
            iframe = document.createElement('iframe');
            iframe.id = 'fluxfiles-iframe';
            iframe.src = endpoint + '/public/index.html';
            iframe.style.cssText = 'width:100%;height:100%;border:none;';
            iframe.setAttribute('allow', 'clipboard-write');

            if (!config.container) {
                // Modal overlay
                var overlay = document.createElement('div');
                overlay.id = 'fluxfiles-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';

                var modal = document.createElement('div');
                modal.id = 'fluxfiles-modal';
                modal.style.cssText = 'width:90vw;height:85vh;max-width:1200px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 25px 50px rgba(0,0,0,0.25);';

                modal.appendChild(iframe);
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
