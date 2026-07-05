/**
 * FluxFiles Gutenberg block — `fluxfiles/image`.
 * No build step: registered with wp.blocks + wp.element.createElement (no JSX).
 * Picks an image from FluxFiles, registers it as a WP attachment (bridge), and
 * inserts an <img> served from your bucket.
 */
(function (blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var __ = i18n.__;
    var cfg = window.FluxFilesBlockCfg || {};

    // A single shared modal container appended to <body> for the FluxFiles picker.
    function ensureModal() {
        var m = document.getElementById('fluxfiles-block-modal');
        if (m) return m;
        m = document.createElement('div');
        m.id = 'fluxfiles-block-modal';
        m.className = 'fluxfiles-modal';
        m.style.display = 'none';
        m.innerHTML =
            '<div class="fluxfiles-modal-overlay"></div>' +
            '<div class="fluxfiles-modal-content">' +
            '<div class="fluxfiles-modal-header"><h2>FluxFiles</h2>' +
            '<button type="button" class="fluxfiles-modal-close">&times;</button></div>' +
            '<div id="fluxfiles-block-body" class="fluxfiles-modal-body"></div></div>';
        document.body.appendChild(m);
        m.querySelector('.fluxfiles-modal-overlay').addEventListener('click', hideModal);
        m.querySelector('.fluxfiles-modal-close').addEventListener('click', hideModal);
        return m;
    }
    function hideModal() {
        var m = document.getElementById('fluxfiles-block-modal');
        if (m) m.style.display = 'none';
    }

    var opened = false;
    function openPicker(onPick) {
        if (!cfg.token || !window.FluxFiles) {
            window.alert(__('FluxFiles is not configured. Set it up in Settings → FluxFiles.', 'fluxfiles'));
            return;
        }
        var m = ensureModal();
        m.style.display = 'flex';
        if (!opened) {
            window.FluxFiles.open({
                endpoint: cfg.endpoint,
                token: cfg.token,
                disk: cfg.disk,
                mode: 'picker',
                locale: cfg.locale,
                container: '#fluxfiles-block-body',
                onTokenRefresh: function () {
                    return fetch(cfg.tokenUrl, {
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' }
                    }).then(function (r) { return r.ok ? r.json() : null; })
                      .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })
                      .catch(function () { return null; });
                },
                onSelect: function (file) {
                    var f = Array.isArray(file) ? file[0] : file;
                    hideModal();
                    attach(f, onPick);
                }
            });
            opened = true;
        }
    }

    // Register the pick as a WP attachment; hand {url,id,alt} back to the block.
    function attach(file, onPick) {
        fetch(cfg.attachUrl, {
            method: 'POST',
            credentials: 'same-origin',
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
          .then(function (j) {
              var alt = (file.meta && (file.meta.alt || file.meta.title)) || file.basename || file.name || '';
              onPick({ url: (j && j.data && j.data.url) || file.url, id: (j && j.data && j.data.id) || 0, alt: alt });
          })
          .catch(function () {
              var alt = (file.meta && (file.meta.alt || file.meta.title)) || file.basename || file.name || '';
              onPick({ url: file.url, id: 0, alt: alt });
          });
    }

    blocks.registerBlockType('fluxfiles/image', {
        apiVersion: 2,
        title: __('FluxFiles Image', 'fluxfiles'),
        description: __('Insert an image from FluxFiles, served from your bucket.', 'fluxfiles'),
        icon: 'format-image',
        category: 'media',
        attributes: {
            url: { type: 'string', default: '' },
            id: { type: 'number', default: 0 },
            alt: { type: 'string', default: '' }
        },
        edit: function (props) {
            var a = props.attributes;
            var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
            function onPick(res) {
                props.setAttributes({ url: res.url, id: res.id, alt: res.alt });
            }
            function setFeatured() {
                if (a.id && window.wp.data && window.wp.data.dispatch('core/editor')) {
                    window.wp.data.dispatch('core/editor').editPost({ featured_media: a.id });
                    if (window.wp.data.dispatch('core/notices')) {
                        window.wp.data.dispatch('core/notices').createInfoNotice(
                            __('Set as featured image.', 'fluxfiles'), { type: 'snackbar', isDismissible: true }
                        );
                    }
                }
            }
            var toolbarButtons = [
                el(components.ToolbarButton, {
                    icon: 'edit', label: __('Replace from FluxFiles', 'fluxfiles'),
                    onClick: function () { openPicker(onPick); }
                })
            ];
            if (a.id) {
                toolbarButtons.push(el(components.ToolbarButton, {
                    icon: 'star-filled', label: __('Set as featured image', 'fluxfiles'),
                    onClick: setFeatured
                }));
            }
            var controls = el(blockEditor.BlockControls, null, el(components.ToolbarGroup, null, toolbarButtons));
            if (!a.url) {
                return el(
                    'div',
                    blockProps,
                    el(
                        components.Placeholder,
                        { icon: 'format-image', label: __('FluxFiles Image', 'fluxfiles'),
                          instructions: __('Pick an image from your FluxFiles storage.', 'fluxfiles') },
                        el(components.Button, { variant: 'primary', onClick: function () { openPicker(onPick); } },
                           __('Choose from FluxFiles', 'fluxfiles'))
                    )
                );
            }
            return el('figure', blockProps, controls, el('img', { src: a.url, alt: a.alt }));
        },
        save: function (props) {
            var a = props.attributes;
            if (!a.url) return null;
            var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps.save() : {};
            var cls = a.id ? 'wp-image-' + a.id : undefined;
            return el('figure', blockProps, el('img', { src: a.url, alt: a.alt, className: cls }));
        }
    });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
