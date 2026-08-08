/**
 * FluxFiles plugin for CKEditor 4.
 *
 * Two integration points:
 *   1. A toolbar button ("FluxFiles") that opens the picker and inserts the
 *      selection directly (<img> for images, <a> otherwise).
 *   2. A "Browse FluxFiles" button injected into the native **Image** dialog
 *      (Image Properties) that opens FluxFiles inline and fills the dialog's
 *      URL + Alt + Width + Height fields — the "Browse Server" / CKFinder
 *      pattern, but without a popup window. Disable with
 *      `config.fluxfiles.imageDialog = false`.
 *
 * Usage:
 *   CKEDITOR.replace('editor', {
 *       extraPlugins: 'fluxfiles',
 *       fluxfiles: {
 *           endpoint: 'http://localhost:8080',
 *           token: 'JWT_TOKEN',
 *           disk: 'local',          // optional
 *           locale: 'en',           // optional
 *           theme: 'dark',          // optional — light | dark | auto
 *           multiple: false          // optional
 *       }
 *   });
 */
(function () {
    'use strict';

    // Shared toolbar glyph — the same folder icon TinyMCE registers, kept inline
    // (no separate image file) so both editor plugins stay visually in sync.
    // currentColor doesn't resolve inside an <img>, so use an explicit gray.
    var ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none">'
        + '<path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" '
        + 'stroke="#575757" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var ICON_URI = 'data:image/svg+xml,' + encodeURIComponent(ICON_SVG);

    // Normalize a selected file into the bits the insert / dialog paths need.
    function fileInfo(file) {
        var url = file.permanent_url || file.url || '';
        var name = file.name || file.basename || file.path || '';
        var meta = file.meta || {};
        // Preview-only selection (no permanent_url/url) — e.g. a watermark OVERLAY
        // with allow_download=false. Its only view is img_base, a short-lived token
        // URL unsafe to save into content. To embed a *watermarked* image, burn the
        // watermark into the file first (Watermark editor → Apply). Refuse here.
        var embeddable = url !== '';
        if (!embeddable) {
            console.warn('[FluxFiles] "' + name + '" is preview-only (no embeddable URL — e.g. a watermark overlay with allow_download=false), so it was not inserted. Burn in the watermark (Watermark editor → Apply) or issue a downloadable token to embed it.');
        } else if (/[?&](X-Amz-|Signature=)/.test(url)) {
            console.warn('[FluxFiles] Inserting a presigned (expiring) URL — it will break when the URL expires. Use a public disk or public_url for embeds.');
        }
        var isImage = (file.mime && file.mime.indexOf('image/') === 0)
            || /\.(jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i.test(name);
        return { url: url, name: name, meta: meta, isImage: isImage, embeddable: embeddable, width: file.width || null, height: file.height || null };
    }

    function open(cfg, multiple, onSelect) {
        if (typeof FluxFiles === 'undefined') {
            console.error('[FluxFiles] SDK (fluxfiles.js) is not loaded.');
            return;
        }
        FluxFiles.open({
            endpoint: cfg.endpoint || '', token: cfg.token || '', disk: cfg.disk || 'local',
            mode: 'picker', multiple: !!multiple,
            maxUploadMb: cfg.maxUploadMb || null, maxFiles: cfg.maxFiles || null, locale: cfg.locale || null,
            theme: cfg.theme || null, disks: cfg.disks || null,
            onSelect: onSelect
        });
    }

    function firstFile(payload) {
        var f = Array.isArray(payload) ? payload[0] : payload;
        return (f && !f.is_dir) ? f : null;
    }

    // ── 2) Native Image dialog: a "Browse FluxFiles" button that fills fields ──
    // Registered once, globally; reads each editor's own config.
    if (!CKEDITOR.fluxfilesDialogHook) {
        CKEDITOR.fluxfilesDialogHook = true;
        CKEDITOR.on('dialogDefinition', function (ev) {
            if (ev.data.name !== 'image' && ev.data.name !== 'image2') return;
            var editor = ev.editor;
            // Only for editors using this plugin, and not opted out.
            var cfg = editor && editor.config && editor.config.fluxfiles;
            if (!cfg || cfg.imageDialog === false) return;

            var def = ev.data.definition;
            var infoTab = def.getContents('info') || def.getContents('Upload');
            if (!infoTab) return;

            infoTab.add({
                type: 'button',
                id: 'fluxfilesBrowse',
                label: 'Browse FluxFiles',
                title: 'Browse FluxFiles',
                onClick: function () {
                    var dialog = this.getDialog();
                    open(cfg, false, function (payload) {
                        var file = firstFile(payload);
                        if (!file) return;
                        var info = fileInfo(file);
                        if (!info.embeddable) return; // preview-only → already warned
                        var set = function (id, val) {
                            var el = dialog.getContentElement('info', id);
                            if (el && val != null && val !== '') el.setValue(String(val));
                        };
                        set('txtUrl', info.url);
                        set('txtAlt', info.meta.alt_text || info.name);
                        if (info.width) set('txtWidth', info.width);
                        if (info.height) set('txtHeight', info.height);
                        // Nudge the dialog to refresh its preview from the new URL.
                        var urlEl = dialog.getContentElement('info', 'txtUrl');
                        if (urlEl && typeof urlEl.onChange === 'function') urlEl.onChange();
                    });
                }
            });
        });
    }

    CKEDITOR.plugins.add('fluxfiles', {
        init: function (editor) {
            var cfg = editor.config.fluxfiles || {};

            // ── 1) Standalone button: insert the selection directly ──────────
            editor.addCommand('openFluxFiles', {
                exec: function () {
                    open(cfg, cfg.multiple, function (payload) {
                        var files = Array.isArray(payload) ? payload : [payload];
                        // Attribute-context values (src/href/alt/title) need htmlEncodeAttr, which
                        // additionally escapes `"` — plain htmlEncode only does &/</> and lets a
                        // quote in e.g. alt_text break out of the attribute (stored XSS).
                        var encAttr = CKEDITOR.tools.htmlEncodeAttr;
                        var enc = CKEDITOR.tools.htmlEncode;
                        for (var i = 0; i < files.length; i++) {
                            var file = files[i];
                            if (!file || file.is_dir) continue;
                            var info = fileInfo(file);
                            if (!info.embeddable) continue; // preview-only → already warned

                            if (info.isImage) {
                                var dim = '';
                                if (info.width) { dim += ' width="' + parseInt(info.width, 10) + '"'; }
                                if (info.height) { dim += ' height="' + parseInt(info.height, 10) + '"'; }
                                editor.insertHtml('<img src="' + encAttr(info.url) + '" alt="' + encAttr(info.meta.alt_text || info.name) + '"' + dim + ' />');
                            } else {
                                editor.insertHtml('<a href="' + encAttr(info.url) + '">' + enc(info.meta.title || info.name) + '</a>');
                            }
                        }
                    });
                }
            });

            editor.ui.addButton('FluxFiles', {
                label: 'FluxFiles',
                command: 'openFluxFiles',
                toolbar: 'insert',
                icon: ICON_URI
            });
        }
    });
})();
