/**
 * FluxFiles plugin for CKEditor 4.
 *
 * Adds a toolbar button that opens the FluxFiles picker.
 * Selected images are inserted as <img>, other files as <a>.
 *
 * Usage:
 *   CKEDITOR.replace('editor', {
 *       extraPlugins: 'fluxfiles',
 *       fluxfiles: {
 *           endpoint: 'http://localhost:8080',
 *           token: 'JWT_TOKEN',
 *           disk: 'local',          // optional
 *           locale: 'en',           // optional
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

    CKEDITOR.plugins.add('fluxfiles', {
        init: function (editor) {
            var cfg = editor.config.fluxfiles || {};

            editor.addCommand('openFluxFiles', {
                exec: function () {
                    if (typeof FluxFiles === 'undefined') {
                        console.error('[FluxFiles] SDK (fluxfiles.js) is not loaded.');
                        return;
                    }

                    FluxFiles.open({
                        endpoint: cfg.endpoint || '',
                        token: cfg.token || '',
                        disk: cfg.disk || 'local',
                        mode: 'picker',
                        multiple: !!cfg.multiple,
                        maxUploadMb: cfg.maxUploadMb || null,
                        maxFiles: cfg.maxFiles || null,
                        locale: cfg.locale || null,
                        onSelect: function (payload) {
                            var files = Array.isArray(payload) ? payload : [payload];
                            var enc = CKEDITOR.tools.htmlEncode;

                            for (var i = 0; i < files.length; i++) {
                                var file = files[i];
                                if (!file || file.is_dir) continue;

                                // Prefer a permanent URL (public disk / public_url) so saved
                                // content doesn't embed an expiring presigned URL.
                                var url = file.permanent_url || file.url || '';
                                var name = file.name || file.basename || file.path || '';
                                var meta = file.meta || {};

                                // Still presigned (private disk, no public_url)? Warn — it will
                                // expire and break the saved link/image.
                                if (/[?&](X-Amz-|Signature=)/.test(url)) {
                                    console.warn('[FluxFiles] Inserting a presigned (expiring) URL — it will break when the URL expires. Use a public disk or public_url for embeds.');
                                }

                                var isImage = (file.mime && file.mime.indexOf('image/') === 0)
                                    || /\.(jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i.test(name);

                                if (isImage) {
                                    var dim = '';
                                    if (file.width) { dim += ' width="' + parseInt(file.width, 10) + '"'; }
                                    if (file.height) { dim += ' height="' + parseInt(file.height, 10) + '"'; }
                                    editor.insertHtml('<img src="' + enc(url) + '" alt="' + enc(meta.alt_text || name) + '"' + dim + ' />');
                                } else {
                                    editor.insertHtml('<a href="' + enc(url) + '">' + enc(meta.title || name) + '</a>');
                                }
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
