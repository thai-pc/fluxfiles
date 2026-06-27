/**
 * FluxFiles plugin for TinyMCE (4.x / 5.x / 6.x compatible).
 *
 * Two integration points:
 *   1. A toolbar/menu button ("fluxfiles") that opens the picker and inserts the
 *      selection directly (<img> for images, <a> otherwise).
 *   2. A `file_picker_callback` wired into the editor so the native
 *      Insert/Edit Image (and link/media) dialog gets a Source "browse" icon that
 *      opens FluxFiles — filling the URL + alt + width/height of the native dialog
 *      (the "CKFinder" pattern). Disable with `fluxfiles_image_dialog: false`, and
 *      a host-provided `file_picker_callback` is always respected.
 *
 * Usage (TinyMCE 4):
 *   tinymce.init({
 *       selector: '#editor',
 *       plugins: 'image link',
 *       external_plugins: { fluxfiles: '/path/to/adapters/tinymce/plugin.js' },
 *       toolbar: 'fluxfiles | image',
 *       fluxfiles_endpoint: 'http://localhost:8080',
 *       fluxfiles_token: 'JWT_TOKEN',
 *       fluxfiles_disk: 'local',
 *       fluxfiles_locale: 'en',
 *       fluxfiles_theme: 'dark',     // optional — light | dark | auto
 *       fluxfiles_multiple: false
 *   });
 *
 * Usage (TinyMCE 5 / 6):
 *   Same config — the plugin auto-detects the version.
 */
(function () {
    'use strict';

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function cfg(editor) {
        return {
            endpoint: editor.getParam('fluxfiles_endpoint', ''),
            token: editor.getParam('fluxfiles_token', ''),
            disk: editor.getParam('fluxfiles_disk', 'local'),
            locale: editor.getParam('fluxfiles_locale', null),
            theme: editor.getParam('fluxfiles_theme', null),
            disks: editor.getParam('fluxfiles_disks', null),
            multiple: editor.getParam('fluxfiles_multiple', false),
            maxUploadMb: editor.getParam('fluxfiles_max_upload_mb', null),
            maxFiles: editor.getParam('fluxfiles_max_files', null)
        };
    }

    // Normalize a selected file into the bits both insert paths need.
    function fileInfo(file) {
        // Prefer a permanent URL (public disk / public_url) so saved content
        // doesn't embed an expiring presigned URL.
        var url = file.permanent_url || file.url || '';
        var name = file.name || file.basename || file.path || '';
        var meta = file.meta || {};

        // Preview-only selection (no permanent_url and no url) — e.g. a watermark
        // OVERLAY image with allow_download=false. Its only view is img_base, a
        // short-lived token URL that must NOT be saved into content (it expires →
        // broken image), and inserting the empty string would yield <img src="">.
        // To embed a *watermarked* image, burn the watermark into the file first
        // (Watermark editor → Apply) — that yields a stable, watermarked
        // permanent_url. So we refuse here rather than insert a clean/broken image.
        var embeddable = url !== '';
        if (!embeddable) {
            console.warn('[FluxFiles] "' + name + '" is preview-only (no embeddable URL — e.g. a watermark overlay with allow_download=false), so it was not inserted. Burn in the watermark (Watermark editor → Apply) or issue a downloadable token to embed it.');
        } else if (/[?&](X-Amz-|Signature=)/.test(url)) {
            // Still presigned (private disk, no public_url)? Warn — it will expire
            // and break the saved link/image.
            console.warn('[FluxFiles] Inserting a presigned (expiring) URL — it will break when the URL expires. Use a public disk or public_url for embeds.');
        }

        var isImage = (file.mime && file.mime.indexOf('image/') === 0)
            || /\.(jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i.test(name);

        return {
            url: url, name: name, meta: meta, isImage: isImage, embeddable: embeddable,
            width: file.width || null, height: file.height || null
        };
    }

    function open(editor, multiple, onSelect) {
        if (typeof FluxFiles === 'undefined') {
            console.error('[FluxFiles] SDK (fluxfiles.js) is not loaded.');
            return;
        }
        var c = cfg(editor);
        FluxFiles.open({
            endpoint: c.endpoint, token: c.token, disk: c.disk,
            mode: 'picker', multiple: !!multiple,
            maxUploadMb: c.maxUploadMb, maxFiles: c.maxFiles, locale: c.locale,
            theme: c.theme, disks: c.disks,
            onSelect: onSelect
        });
    }

    // ── 1) Standalone button: insert the selection directly ─────────────────
    function openPicker(editor) {
        open(editor, cfg(editor).multiple, function (payload) {
            var files = Array.isArray(payload) ? payload : [payload];
            for (var i = 0; i < files.length; i++) {
                var file = files[i];
                if (!file || file.is_dir) continue;
                var info = fileInfo(file);
                if (!info.embeddable) continue; // preview-only → already warned

                if (info.isImage) {
                    var dim = '';
                    if (info.width) { dim += ' width="' + parseInt(info.width, 10) + '"'; }
                    if (info.height) { dim += ' height="' + parseInt(info.height, 10) + '"'; }
                    editor.insertContent('<img src="' + escHtml(info.url) + '" alt="' + escHtml(info.meta.alt_text || info.name) + '"' + dim + ' />');
                } else {
                    editor.insertContent('<a href="' + escHtml(info.url) + '">' + escHtml(info.meta.title || info.name) + '</a>');
                }
            }
        });
    }

    // ── 2) Native dialog source: file_picker_callback ───────────────────────
    function makeFilePicker(editor) {
        // Signature per TinyMCE: (callback, value, meta) => …; meta.filetype is
        // 'image' | 'media' | 'file' depending on which dialog opened it.
        return function (callback, value, meta) {
            open(editor, false, function (payload) {
                var file = Array.isArray(payload) ? payload[0] : payload;
                if (!file || file.is_dir) return;
                var info = fileInfo(file);
                if (!info.embeddable) return; // preview-only → already warned
                var data = {};

                if (meta && meta.filetype === 'image') {
                    data.alt = info.meta.alt_text || info.name;
                    if (info.width) { data.width = String(info.width); }
                    if (info.height) { data.height = String(info.height); }
                } else if (meta && meta.filetype === 'media') {
                    // Source field only; the dialog handles the rest.
                } else {
                    data.text = info.meta.title || info.name;
                    data.title = info.meta.title || info.name;
                }
                callback(info.url, data);
            });
        };
    }

    // Register the picker so the native image/link/media dialogs can browse
    // FluxFiles. Respects a host-provided file_picker_callback and the
    // `fluxfiles_image_dialog: false` opt-out.
    function wireFilePicker(editor) {
        if (editor.getParam('fluxfiles_image_dialog', true) === false) return;
        if (typeof editor.getParam('file_picker_callback') === 'function') return;

        var fn = makeFilePicker(editor);

        // v4: dialogs read editor.settings at open time.
        if (editor.settings) {
            editor.settings.file_picker_callback = fn;
            if (!editor.settings.file_picker_types) {
                editor.settings.file_picker_types = 'file image media';
            }
        }
        // v5 / v6: the options registry is the source of truth.
        try {
            if (editor.options && typeof editor.options.set === 'function') {
                editor.options.set('file_picker_callback', fn);
                if (!editor.options.get('file_picker_types')) {
                    editor.options.set('file_picker_types', 'file image media');
                }
            }
        } catch (e) { /* option not registered yet — the settings path covers v4/older */ }
    }

    // Single registration — version check inside callback (safe regardless of load order)
    tinymce.PluginManager.add('fluxfiles', function (editor) {
        wireFilePicker(editor);

        var majorVersion = parseInt(tinymce.majorVersion, 10);

        if (majorVersion >= 5) {
            // TinyMCE 5+ API — register custom icon (browse doesn't exist in v5)
            editor.ui.registry.addIcon('fluxfiles',
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="none">'
                + '<path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" '
                + 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                + '</svg>'
            );

            editor.ui.registry.addButton('fluxfiles', {
                icon: 'fluxfiles',
                tooltip: 'FluxFiles',
                onAction: function () {
                    openPicker(editor);
                }
            });

            editor.ui.registry.addMenuItem('fluxfiles', {
                icon: 'fluxfiles',
                text: 'FluxFiles',
                onAction: function () {
                    openPicker(editor);
                }
            });

            return {
                getMetadata: function () {
                    return {
                        name: 'FluxFiles',
                        url: 'https://github.com/thai-pc/fluxfiles'
                    };
                }
            };
        } else {
            // TinyMCE 4.x API
            editor.addButton('fluxfiles', {
                icon: 'browse',
                tooltip: 'FluxFiles',
                onclick: function () {
                    openPicker(editor);
                }
            });

            editor.addMenuItem('fluxfiles', {
                icon: 'browse',
                text: 'FluxFiles',
                onclick: function () {
                    openPicker(editor);
                },
                context: 'insert'
            });
        }
    });
})();
