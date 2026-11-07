/* FluxFiles media-button picker modal. Config arrives via window.fluxfilesMediaButton (see FluxFilesMediaButton::renderModal). */
(function() {
    var boot = window.fluxfilesMediaButton;
    if (!boot) return;
    var btn = document.getElementById('fluxfiles-media-btn');
    var modal = document.getElementById('fluxfiles-modal');
    var overlay = modal.querySelector('.fluxfiles-modal-overlay');
    var closeBtn = modal.querySelector('.fluxfiles-modal-close');
    var body = document.getElementById('fluxfiles-modal-body');
    var config = boot.config;
    var PU = window.FluxFilesPickerUrl;
    var refused = [];

    function openModal() {
        modal.style.display = 'flex';
        config.container = '#fluxfiles-modal-body';
        config.onTokenRefresh = function () {
            return fetch(boot.tokenUrl, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })
                .catch(function () { return null; });
        };
        config.onSelect = function(file) {
            var picks = Array.isArray(file) ? file : [file];
            Promise.all(picks.map(insertFile)).then(flushRefused);
            closeModal();
        };
        FluxFiles.open(config);
    }

    function closeModal() {
        modal.style.display = 'none';
        // The SDK keeps one live iframe; close it so the next open starts fresh with its own callback.
        if (typeof FluxFiles !== 'undefined' && FluxFiles.close) FluxFiles.close();
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    // `permanentUrl` is the attachment's own (stable) URL when the bridge made one.
    function emit(file, attachId, permanentUrl) {
        // Never save an expiring /img|/stream ?token= link (private local / SFTP): it
        // 403s after an hour. Only http(s) URLs; otherwise refuse, visibly.
        var r = PU.resolve(permanentUrl ? { url: permanentUrl } : file, config.endpoint);
        var url = r.url;
        if (url === '') {
            refused.push(PU.refusalMessage(r.reason, file.basename || file.name || file.path || file.key || '', window.wp && window.wp.i18n));
            return;
        }
        var name = file.basename || file.name || file.path || file.key || '';
        var alt = (file.meta && (file.meta.alt || file.meta.title)) || name;
        var isImg = /\.(jpe?g|png|gif|webp|avif|svg)$/i.test(name);
        var html;
        if (isImg) {
            // A real attachment reference (wp-image-<id>) so WP treats it like a
            // library image; falls back to a bare <img> if the bridge is off.
            var cls = attachId ? ' class="wp-image-' + attachId + '"' : '';
            html = '<img src="' + esc(url) + '" alt="' + esc(alt) + '"' + cls + ' />';
        } else {
            html = '<a href="' + esc(url) + '">' + esc(name) + '</a>';
        }
        if (typeof window.send_to_editor === 'function') {
            window.send_to_editor(html);
        }
    }

    // One plain-text alert per selection (alert() never renders HTML, so file names are safe).
    function flushRefused() {
        if (!refused.length) return;
        var lines = refused.slice(0, 5);
        if (refused.length > 5) lines.push('… +' + (refused.length - 5));
        refused = [];
        window.alert(lines.join('\n'));
    }

    function insertFile(file) {
        // Register the pick as a WP attachment (served from your bucket), then
        // insert it. If the bridge fails/returns nothing, still insert the URL.
        return fetch(boot.attachUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce },
            body: JSON.stringify({
                url: file.url, key: file.key || file.path, disk: file.disk || config.disk,
                name: file.basename || file.name, mime: file.mime || file.type,
                width: file.width || (file.meta && file.meta.width) || 0,
                height: file.height || (file.meta && file.meta.height) || 0,
                alt: (file.meta && (file.meta.alt || file.meta.title)) || '',
                caption: (file.meta && file.meta.caption) || '',
                variants: file.variants || null
            })
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { emit(file, j && j.data && j.data.id, j && j.data && j.data.id && j.data.url); })
            .catch(function () { emit(file, 0); });
    }

    if (btn) btn.addEventListener('click', function(e) {
        e.preventDefault();
        openModal();
    });
    if (overlay) overlay.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display !== 'none') {
            closeModal();
        }
    });
})();
