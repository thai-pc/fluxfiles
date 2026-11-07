/**
 * FluxFiles plugin for Summernote (jQuery WYSIWYG).
 *
 * Adds a toolbar button that opens the FluxFiles picker.
 * Selected images are inserted as <img>, other files as <a>.
 *
 * Usage:
 *   $('#editor').summernote({
 *       toolbar: [['insert', ['fluxfiles']]],
 *       fluxfiles: {
 *           endpoint: 'http://localhost:8080',
 *           token: 'JWT_TOKEN',
 *           disk: 'local',          // optional
 *           locale: 'en',           // optional
 *           theme: 'dark',          // optional — light | dark | auto
 *           multiple: false          // optional
 *       }
 *   });
 *
 * Requires jQuery + Summernote loaded first, and the FluxFiles SDK
 * (fluxfiles.js) on the host page.
 */
(function (factory) {
    if (typeof define === 'function' && define.amd) {
        define(['jquery'], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('jquery'));
    } else {
        factory(window.jQuery);
    }
}(function ($) {
    'use strict';

    // Bail quietly (with a hint) if the host page hasn't loaded jQuery+Summernote.
    if (!$ || !$.summernote) {
        if (typeof console !== 'undefined') {
            console.error('[FluxFiles] jQuery + Summernote must be loaded before the FluxFiles plugin.');
        }
        return;
    }

    // The FluxFiles logo's square folder silhouette with an "F", as a line icon, identical
    // in the CKEditor 4, CKEditor 5 and TinyMCE plugins, kept inline so they stay
    // visually in sync. currentColor matches the
    // Summernote toolbar text colour.
    var ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:text-bottom">'
        + '<path d="M3 5a2 2 0 012-2h5l2 2h7a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V5z" '
        + 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        // The "F" tells it apart from the editor's own folder/browse buttons.
        + '<path d="M10 16.5v-7h4.5M10 13h3.5" '
        + 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    // Encode a value for use inside an HTML ATTRIBUTE (src="...", alt="...", href="...").
    // The DOM textNode→innerHTML trick alone only encodes &/</>  (correct for text-node
    // context) but leaves `"` untouched, letting a quote in e.g. alt_text break out of the
    // attribute and inject arbitrary attributes/script (stored XSS). Order matters: encode
    // `&` first (via the DOM trick) so the following quote replacements aren't re-escaped.
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
    // One combined message per selection (at most 5 lines).
    function joinRefusals(msgs) {
        var out = msgs.slice(0, 5);
        if (msgs.length > 5) { out.push('… and ' + (msgs.length - 5) + ' more'); }
        return out;
    }
    // Summernote has no notification API: one alert (plain text, so no HTML escaping needed).
    function notifyUser(msgs) {
        if (!msgs.length) return;
        if (typeof window.alert === 'function') { try { window.alert(joinRefusals(msgs).join('\n')); } catch (e) { /* ignore */ } }
    }

    // Build the HTML to insert for one selected file (shared contract with the
    // other editor plugins: <img> for images, <a> otherwise).
    function fileToHtml(file, endpoint, refused) {
        var name = file.name || file.basename || file.path || '';
        // Prefer a permanent URL (public disk / public_url) so saved content
        // doesn't embed an expiring presigned URL.
        var raw = file.permanent_url || file.url || '';
        var url = absUrl(raw, endpoint);
        var reason = refusalReason(raw, url);
        var meta = file.meta || {};

        // Preview-only selection (no permanent_url/url) — e.g. a watermark OVERLAY
        // with allow_download=false. Its only view is img_base, a short-lived token
        // URL unsafe to save into content. To embed a *watermarked* image, burn the
        // watermark into the file first (Watermark editor → Apply). Return '' so the
        // caller skips it (rather than inserting <img src="">).
        if (reason) {
            var msg = refusalMessage(reason, name);
            console.warn('[FluxFiles] ' + msg);
            refused.push(msg);
            return '';
        }

        // Still presigned (private disk, no public_url)? Warn — it will expire
        // and break the saved link/image.
        if (/[?&](X-Amz-|Signature=)/.test(url)) {
            console.warn('[FluxFiles] Inserting a presigned (expiring) URL — it will break when the URL expires. Use a public disk or public_url for embeds.');
        }

        var isImage = (file.mime && file.mime.indexOf('image/') === 0)
            || /\.(jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i.test(name);

        if (isImage) {
            var dim = '';
            if (file.width) { dim += ' width="' + parseInt(file.width, 10) + '"'; }
            if (file.height) { dim += ' height="' + parseInt(file.height, 10) + '"'; }
            return '<img src="' + escHtml(url) + '" alt="' + escHtml(meta.alt_text || name) + '"' + dim + ' />';
        }
        return '<a href="' + escHtml(url) + '">' + escHtml(meta.title || name) + '</a>';
    }

    function openPicker(context) {
        if (typeof FluxFiles === 'undefined') {
            console.error('[FluxFiles] SDK (fluxfiles.js) is not loaded.');
            return;
        }

        var opts = (context.options && context.options.fluxfiles) || {};

        // Remember the cursor: opening the modal steals focus, which drops
        // Summernote's editing range. Without this, pasteHTML inserts at the
        // start of the content and Summernote throws on the lost range.
        context.invoke('editor.saveRange');

        FluxFiles.open({
            endpoint: opts.endpoint || '',
            token: opts.token || '',
            disk: opts.disk || 'local',
            mode: 'picker',
            multiple: !!opts.multiple,
            maxUploadMb: opts.maxUploadMb || null,
            maxFiles: opts.maxFiles || null,
            locale: opts.locale || null,
            theme: opts.theme || null,
            disks: opts.disks || null,
            allowedTypes: opts.allowedTypes || null,
            path: opts.path || '',
            onSelect: function (payload) {
                var files = Array.isArray(payload) ? payload : [payload];

                // Insertable = real files (skip folders / empty entries).
                var insertable = files.filter(function (f) { return f && !f.is_dir; });
                if (insertable.length === 0) return;

                // Restore the editing range + focus before inserting so content
                // lands at the original cursor (and Summernote has a valid range).
                context.invoke('editor.restoreRange');
                context.invoke('editor.focus');

                var refused = [];
                for (var i = 0; i < insertable.length; i++) {
                    var html = fileToHtml(insertable[i], opts.endpoint, refused);
                    if (html === '') continue; // refused → reported below
                    context.invoke('editor.pasteHTML', html);
                }
                notifyUser(refused);
            }
        });
    }

    // Register as a Summernote plugin. The toolbar button name is `fluxfiles`.
    $.extend($.summernote.plugins, {
        'fluxfiles': function (context) {
            var ui = $.summernote.ui;

            context.memo('button.fluxfiles', function () {
                var button = ui.button({
                    contents: ICON_SVG,
                    tooltip: 'FluxFiles',
                    click: function () {
                        openPicker(context);
                    }
                });
                return button.render();
            });
        }
    });
}));
