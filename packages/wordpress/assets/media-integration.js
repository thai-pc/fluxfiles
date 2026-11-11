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
    function ensurePicker() {
        var m = document.getElementById('fluxfiles-native-modal');
        if (m) { return m; }
        m = document.createElement('div');
        m.id = 'fluxfiles-native-modal';
        m.className = 'fluxfiles-modal';
        m.style.display = 'none';
        m.style.zIndex = '600000'; // above wp.media (160000) AND the Customizer's media modal (~560000)
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
        // Drop the iframe + message listener; each open() builds a fresh one.
        if (window.FluxFiles && window.FluxFiles.close) { window.FluxFiles.close(); }
    }
    function openPicker(onSelect, multiple) {
        var m = ensurePicker();
        m.style.display = 'flex';
        {
            window.FluxFiles.open({
                endpoint: cfg.endpoint, token: cfg.token, disk: cfg.disk, disks: cfg.disks, mode: 'picker', locale: cfg.locale,
                multiple: !!multiple,
                container: '#fluxfiles-native-body',
                onTokenRefresh: function () {
                    return fetch(cfg.tokenUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' } })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })
                        .catch(function () { return null; });
                },
                onSelect: function (file) { hidePicker(); onSelect(Array.isArray(file) ? file : [file]); }
            });
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
                caption: (file.meta && file.meta.caption) || '',
                variants: file.variants || null
            })
        }).then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) { return (j && j.data && j.data.id) ? j.data.id : 0; });
    }

    // Gallery / Classic "Add Media" frames take many files; Featured Image / Image block take one.
    function frameAllowsMultiple() {
        var f = window.wp.media.frame, st = f && f.state && f.state();
        return !!(st && st.get && st.get('multiple'));
    }

    // Feed the picked attachment into whatever wp.media frame is open, so WP's own
    // "Insert" / "Set featured image" / block flow completes with our attachment.
    function selectInFrame(attachIds) {
        var frame = window.wp.media.frame;
        attachIds = [].concat(attachIds).filter(Boolean);
        if (!frame || !attachIds.length) { return; }
        var atts = attachIds.map(function (id) { return window.wp.media.attachment(id); });
        // Single-select frames (Featured Image, Image block) keep only the first pick;
        // multi-select frames (Gallery, Classic "Add Media") get all of them.
        var multiple = frame.state() && frame.state().get('multiple');
        if (!multiple) { atts = atts.slice(0, 1); }
        jQuery.when.apply(jQuery, atts.map(function (a) { return a.fetch(); })).always(function () {
            try {
                var sel = frame.state() && frame.state().get('selection');
                if (sel) { sel.reset(atts); }
                // Same order as WP's own Select button: close the modal FIRST, then fire
                // 'select'. Firing it on an open frame lets Gutenberg unmount/dispose the
                // frame (image block replaced by the image), leaving a blank modal on screen.
                // Cropping frames (Customizer logo / header / site icon) drive their own
                // open -> crop -> close sequence from the 'select' event, so don't pre-close them.
                var cropping = !!(frame.states && frame.states.get && frame.states.get('cropper'));
                if (frame.close && !cropping) { frame.close(); }
                // Featured Image / blocks / Customizer complete on 'select'; the Classic editor's
                // "Add Media" (state 'insert') completes on 'insert' with the selection, exactly
                // as its own "Insert into post" button does. Firing 'select' there did nothing.
                var st = frame.state();
                if (st && st.trigger) {
                    if (st.id === 'insert') { st.trigger('insert', sel || st.get('selection')); }
                    else { st.trigger('select'); }
                }
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
            openPicker(function (files) {
                Promise.all(files.map(attach)).then(function (ids) {
                    var id = ids.filter(Boolean);
                    if (!id.length) {
                        // The bridge refused (e.g. private local storage has no permanent URL):
                        // say so instead of silently doing nothing. alert() is plain text.
                        var i18n = window.wp && window.wp.i18n;
                        var __ = (i18n && i18n.__) || function (t) { return t; };
                        window.alert(__('This file could not be added to the Media Library. Files on private storage have no permanent link; use a public disk or set a public URL for it.', 'fluxfiles'));
                        return;
                    }
                    selectInFrame(id);
                }, function () {});
            }, frameAllowsMultiple());
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
