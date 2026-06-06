/**
 * FluxFiles plugin for TinyMCE (4.x / 5.x compatible).
 *
 * Adds a toolbar button that opens the FluxFiles picker.
 * Selected images are inserted as <img>, other files as <a>.
 *
 * Usage (TinyMCE 4):
 *   tinymce.init({
 *       selector: '#editor',
 *       external_plugins: { fluxfiles: '/path/to/adapters/tinymce/plugin.js' },
 *       toolbar: 'fluxfiles',
 *       fluxfiles_endpoint: 'http://localhost:8080',
 *       fluxfiles_token: 'JWT_TOKEN',
 *       fluxfiles_disk: 'local',
 *       fluxfiles_locale: 'en',
 *       fluxfiles_multiple: false
 *   });
 *
 * Usage (TinyMCE 5):
 *   Same config — the plugin auto-detects the version.
 */
(function () {
    'use strict';

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function openPicker(editor) {
        if (typeof FluxFiles === 'undefined') {
            console.error('[FluxFiles] SDK (fluxfiles.js) is not loaded.');
            return;
        }

        var endpoint = editor.getParam('fluxfiles_endpoint', '');
        var token = editor.getParam('fluxfiles_token', '');
        var disk = editor.getParam('fluxfiles_disk', 'local');
        var locale = editor.getParam('fluxfiles_locale', null);
        var multiple = editor.getParam('fluxfiles_multiple', false);
        var maxUploadMb = editor.getParam('fluxfiles_max_upload_mb', null);
        var maxFiles = editor.getParam('fluxfiles_max_files', null);

        FluxFiles.open({
            endpoint: endpoint,
            token: token,
            disk: disk,
            mode: 'picker',
            multiple: !!multiple,
            maxUploadMb: maxUploadMb,
            maxFiles: maxFiles,
            locale: locale,
            onSelect: function (payload) {
                var files = Array.isArray(payload) ? payload : [payload];

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
                        editor.insertContent('<img src="' + escHtml(url) + '" alt="' + escHtml(meta.alt_text || name) + '"' + dim + ' />');
                    } else {
                        editor.insertContent('<a href="' + escHtml(url) + '">' + escHtml(meta.title || name) + '</a>');
                    }
                }
            }
        });
    }

    // Single registration — version check inside callback (safe regardless of load order)
    tinymce.PluginManager.add('fluxfiles', function (editor) {
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
