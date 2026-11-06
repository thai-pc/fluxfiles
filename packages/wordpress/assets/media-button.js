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
    var opened = false;

    function openModal() {
        modal.style.display = 'flex';
        if (!opened) {
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
                if (Array.isArray(file)) {
                    file.forEach(function(f) { insertFile(f); });
                } else {
                    insertFile(file);
                }
                closeModal();
            };
            FluxFiles.open(config);
            opened = true;
        }
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function emit(file, attachId) {
        var url = file.url || '';
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

    function insertFile(file) {
        // Register the pick as a WP attachment (served from your bucket), then
        // insert it. If the bridge fails/returns nothing, still insert the URL.
        fetch(boot.attachUrl, {
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
            .then(function (j) { emit(file, j && j.data && j.data.id); })
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
