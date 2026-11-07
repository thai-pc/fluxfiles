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

    // Shared toolbar glyph: the FluxFiles logo's square folder silhouette with an
    // "F", as a monochrome line icon, identical in the CKEditor 4, CKEditor 5,
    // TinyMCE and Summernote plugins. Kept inline (no separate image file).
    // currentColor doesn't resolve inside an <img>, so use an explicit gray.
    var ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none">'
        + '<path d="M3 5a2 2 0 012-2h5l2 2h7a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V5z" '
        + 'fill="none" stroke="#575757" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        // The "F" tells it apart from the editor's own folder/browse buttons.
        + '<path d="M10 16.5v-7h4.5M10 13h3.5" '
        + 'fill="none" stroke="#575757" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var ICON_URI = 'data:image/svg+xml,' + encodeURIComponent(ICON_SVG);

    // CKEditor resolves a button `icon` through CKEDITOR.getUrl(), which only
    // recognises http(s):// and /-rooted paths, so a data: URI came out as
    // "<basePath>data:image/svg+xml,…" and the button showed no icon. Set the
    // glyph with a stylesheet rule instead (once per page).
    var iconCssAdded = false;
    function addIconCss() {
        if (iconCssAdded || !CKEDITOR.document || !CKEDITOR.document.appendStyleText) {
            return;
        }
        iconCssAdded = true;
        CKEDITOR.document.appendStyleText(
            '.cke_button__fluxfiles_icon{background:url("' + ICON_URI + '") no-repeat 0 0 / 16px 16px !important}'
        );
    }

    // Selected URLs must be absolute and permanent. Older cores return a
    // root-relative url ('/storage/uploads/x') that would resolve against the HOST
    // page; resolve it against the FluxFiles endpoint's origin. And never let an
    // expiring .../img|stream ?token= URL reach saved content.
    function absUrl(u, endpoint) {
        if (typeof u !== 'string') return '';
        // Strip leading whitespace/control chars first: browsers ignore them, so
        // " \tjavascript:..." would otherwise slip past a scheme check.
        var t = u.replace(/^[\u0000-\u0020]+/, '');
        if (t === '') return '';
        var r = null;
        try { r = new URL(t, endpoint || window.location.href); } catch (e) {
            try { r = new URL(t, window.location.href); } catch (e2) { return ''; }
        }
        // Only http(s) may reach saved content (drops javascript:, data:, vbscript:, ...).
        return (r.protocol === 'http:' || r.protocol === 'https:') ? r.href : '';
    }
    function isTokenUrl(u) {
        return /\/(img|stream)(\.php)?\?([^#]*&)?token=/.test(u);
    }
    // Why a selection cannot be inserted: 'preview' (no URL), 'unsafe' (not http/https),
    // 'token' (only an expiring /img|/stream token link, e.g. a private local or SFTP disk).
    function refusalReason(raw, url) {
        if (raw === '') return 'preview';
        if (url === '') return 'unsafe';
        return isTokenUrl(url) ? 'token' : '';
    }
    function refusalMessage(reason, name) {
        if (reason === 'token') {
            return '"' + name + '" is only available through an expiring link (private disk without a public URL), so it was not inserted. Use a public disk or set public_url.';
        }
        if (reason === 'unsafe') {
            return '"' + name + '" has a URL that is not http(s), so it was not inserted.';
        }
        return '"' + name + '" is preview-only (no embeddable URL, e.g. a watermark overlay with allow_download=false), so it was not inserted. Burn in the watermark (Watermark editor, Apply) or issue a downloadable token to embed it.';
    }
    // User-visible refusal (a console.warn alone is invisible to editors).
    // One combined message per selection (at most 5 lines).
    function joinRefusals(msgs) {
        var out = msgs.slice(0, 5);
        if (msgs.length > 5) { out.push('… and ' + (msgs.length - 5) + ' more'); }
        return out;
    }
    // showNotification() renders its argument as HTML and the messages carry file
    // names (which can hold markup on storage written by other tools): encode them.
    function notifyUser(editor, msgs) {
        if (!msgs.length) return;
        var lines = joinRefusals(msgs);
        try {
            if (editor && typeof editor.showNotification === 'function') {
                editor.showNotification(lines.map(CKEDITOR.tools.htmlEncode).join('<br>'), 'warning');
                return;
            }
        } catch (e) { /* fall through */ }
        if (typeof window.alert === 'function') { try { window.alert(lines.join('\n')); } catch (e) { /* ignore */ } }
    }

    // Normalize a selected file into the bits the insert / dialog paths need.
    function fileInfo(file, endpoint, notify) {
        var raw = file.permanent_url || file.url || '';
        var url = absUrl(raw, endpoint);
        var reason = refusalReason(raw, url);
        if (reason) { url = ''; } // expiring token / non-http(s) URL: never save it
        var name = file.name || file.basename || file.path || '';
        var meta = file.meta || {};
        // Preview-only selection (no permanent_url/url) — e.g. a watermark OVERLAY
        // with allow_download=false. Its only view is img_base, a short-lived token
        // URL unsafe to save into content. To embed a *watermarked* image, burn the
        // watermark into the file first (Watermark editor → Apply). Refuse here.
        var embeddable = url !== '';
        if (!embeddable) {
            var msg = refusalMessage(reason, name);
            console.warn('[FluxFiles] ' + msg);
            if (notify) { notify(msg); }
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
            allowedTypes: cfg.allowedTypes || null, path: cfg.path || '',
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
                        var refused = [];
                        var info = fileInfo(file, cfg.endpoint, function (m) { refused.push(m); });
                        notifyUser(editor, refused);
                        if (!info.embeddable) return; // refused → already reported
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
                        var refused = [];
                        for (var i = 0; i < files.length; i++) {
                            var file = files[i];
                            if (!file || file.is_dir) continue;
                            var info = fileInfo(file, cfg.endpoint, function (m) { refused.push(m); });
                            if (!info.embeddable) continue; // refused → reported below

                            if (info.isImage) {
                                var dim = '';
                                if (info.width) { dim += ' width="' + parseInt(info.width, 10) + '"'; }
                                if (info.height) { dim += ' height="' + parseInt(info.height, 10) + '"'; }
                                editor.insertHtml('<img src="' + encAttr(info.url) + '" alt="' + encAttr(info.meta.alt_text || info.name) + '"' + dim + ' />');
                            } else {
                                editor.insertHtml('<a href="' + encAttr(info.url) + '">' + enc(info.meta.title || info.name) + '</a>');
                            }
                        }
                        notifyUser(editor, refused);
                    });
                }
            });

            addIconCss();
            editor.ui.addButton('FluxFiles', {
                label: 'FluxFiles',
                command: 'openFluxFiles',
                toolbar: 'insert',
                icon: ICON_URI
            });
        }
    });
})();
