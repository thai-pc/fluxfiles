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

        FluxFiles.open({
            endpoint: endpoint,
            token: token,
            disk: disk,
            mode: 'picker',
            multiple: !!multiple,
            locale: locale,
            onSelect: function (payload) {
                var files = Array.isArray(payload) ? payload : (payload && payload.files ? payload.files : [payload]);

                for (var i = 0; i < files.length; i++) {
                    var file = files[i];
                    if (!file) continue;

                    var url = file.url || '';
                    var name = file.basename || file.name || file.path || '';

                    if (/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i.test(name)) {
                        editor.insertContent('<img src="' + escHtml(url) + '" alt="' + escHtml(name) + '" />');
                    } else {
                        editor.insertContent('<a href="' + escHtml(url) + '">' + escHtml(name) + '</a>');
                    }
                }
            }
        });
    }

    // Single registration — version check inside callback (safe regardless of load order)
    tinymce.PluginManager.add('fluxfiles', function (editor) {
        var majorVersion = parseInt(tinymce.majorVersion, 10);

        if (majorVersion >= 5) {
            // TinyMCE 5+ API
            editor.ui.registry.addButton('fluxfiles', {
                icon: 'browse',
                tooltip: 'FluxFiles',
                onAction: function () {
                    openPicker(editor);
                }
            });

            editor.ui.registry.addMenuItem('fluxfiles', {
                icon: 'browse',
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
