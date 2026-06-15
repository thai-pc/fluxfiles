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

    // Same folder glyph the CKEditor 4 / TinyMCE plugins use, kept inline so the
    // three editor plugins stay visually in sync. currentColor matches the
    // Summernote toolbar text colour.
    var ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:text-bottom">'
        + '<path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" '
        + 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    // Build the HTML to insert for one selected file (shared contract with the
    // other editor plugins: <img> for images, <a> otherwise).
    function fileToHtml(file) {
        // Prefer a permanent URL (public disk / public_url) so saved content
        // doesn't embed an expiring presigned URL.
        var url = file.permanent_url || file.url || '';
        var name = file.name || file.basename || file.path || '';
        var meta = file.meta || {};

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
            onSelect: function (payload) {
                var files = Array.isArray(payload) ? payload : [payload];

                // Insertable = real files (skip folders / empty entries).
                var insertable = files.filter(function (f) { return f && !f.is_dir; });
                if (insertable.length === 0) return;

                // Restore the editing range + focus before inserting so content
                // lands at the original cursor (and Summernote has a valid range).
                context.invoke('editor.restoreRange');
                context.invoke('editor.focus');

                for (var i = 0; i < insertable.length; i++) {
                    context.invoke('editor.pasteHTML', fileToHtml(insertable[i]));
                }
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
