/**
 * Native media-modal integration (experimental).
 * Injects a "From FluxFiles" button into WordPress's wp.media modal so Featured Image,
 * the core Image block, the Customizer, etc. can pull from FluxFiles. On select it
 * registers a WP attachment (bridge) and adds it to the frame's selection so WP's own
 * flow (insert / set featured / etc.) completes normally.
 *
 * No build step — uses the global wp.media + a DOM observer (most compatible; avoids
 * subclassing every frame type). Self-disables if wp.media / a token is unavailable.
 */
(function () {
    var cfg = window.FluxFilesMediaCfg || {};
    if (!cfg.token || !window.wp || !window.wp.media) { return; }

    // ---- shared FluxFiles picker modal (appended once) ----
    var pickerOpened = false;
    function ensurePicker() {
        var m = document.getElementById('fluxfiles-native-modal');
        if (m) { return m; }
        m = document.createElement('div');
        m.id = 'fluxfiles-native-modal';
        m.className = 'fluxfiles-modal';
        m.style.display = 'none';
        m.style.zIndex = '200000'; // above wp.media (160000)
        m.innerHTML =
            '<div class="fluxfiles-modal-overlay"></div>' +
            '<div class="fluxfiles-modal-content">' +
            '<div class="fluxfiles-modal-header"><h2>FluxFiles</h2>' +
            '<button type="button" class="fluxfiles-modal-close">&times;</button></div>' +
            '<div id="fluxfiles-native-body" class="fluxfiles-modal-body"></div></div>';
        document.body.appendChild(m);
        m.querySelector('.fluxfiles-modal-overlay').addEventListener('click', hidePicker);
        m.querySelector('.fluxfiles-modal-close').addEventListener('click', hidePicker);
        return m;
    }
    function hidePicker() {
        var m = document.getElementById('fluxfiles-native-modal');
        if (m) { m.style.display = 'none'; }
    }
    function openPicker(onSelect) {
        var m = ensurePicker();
        m.style.display = 'flex';
        if (!pickerOpened) {
            window.FluxFiles.open({
                endpoint: cfg.endpoint, token: cfg.token, disk: cfg.disk, mode: 'picker', locale: cfg.locale,
                container: '#fluxfiles-native-body',
                onTokenRefresh: function () {
                    return fetch(cfg.tokenUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' } })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })
                        .catch(function () { return null; });
                },
                onSelect: function (file) { hidePicker(); onSelect(Array.isArray(file) ? file[0] : file); }
            });
            pickerOpened = true;
        }
    }

    // Register the pick as a WP attachment; resolve with its numeric id.
    function attach(file) {
        return fetch(cfg.attachUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({
                url: file.url, key: file.key || file.path, disk: file.disk || cfg.disk,
                name: file.basename || file.name, mime: file.mime || file.type,
                width: file.width || (file.meta && file.meta.width) || 0,
                height: file.height || (file.meta && file.meta.height) || 0,
                alt: (file.meta && (file.meta.alt || file.meta.title)) || '',
                caption: (file.meta && file.meta.caption) || ''
            })
        }).then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) { return (j && j.data && j.data.id) ? j.data.id : 0; });
    }

    // Feed the picked attachment into whatever wp.media frame is open, so WP's own
    // "Insert" / "Set featured image" / block flow completes with our attachment.
    function selectInFrame(attachId) {
        var frame = window.wp.media.frame;
        if (!frame || !attachId) { return; }
        var att = window.wp.media.attachment(attachId);
        att.fetch().always(function () {
            try {
                var sel = frame.state() && frame.state().get('selection');
                if (sel) { sel.reset([att]); }
                // Most select frames listen on this; insert/featured complete on it.
                if (frame.state() && frame.state().trigger) { frame.state().trigger('select'); }
            } catch (e) { /* unusual frame → leave the modal for the user */ }
        });
    }

    // Inject the button into any media modal toolbar that appears.
    function injectButton(modal) {
        var toolbar = modal.querySelector('.media-frame-toolbar .media-toolbar');
        if (!toolbar || toolbar.querySelector('.fluxfiles-from-btn')) { return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button button-secondary fluxfiles-from-btn';
        btn.textContent = cfg.label || 'From FluxFiles';
        btn.style.marginRight = '8px';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openPicker(function (file) { attach(file).then(selectInFrame); });
        });
        var secondary = toolbar.querySelector('.media-toolbar-secondary') || toolbar;
        secondary.insertBefore(btn, secondary.firstChild);
    }

    var observer = new MutationObserver(function () {
        document.querySelectorAll('.media-modal').forEach(injectButton);
    });
    observer.observe(document.body, { childList: true, subtree: true });
    // Also handle an already-open modal.
    document.querySelectorAll('.media-modal').forEach(injectButton);
})();
