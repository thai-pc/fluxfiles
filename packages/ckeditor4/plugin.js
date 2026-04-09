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

    CKEDITOR.plugins.add('fluxfiles', {
        icons: 'fluxfiles',
        hidpi: true,

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
                        locale: cfg.locale || null,
                        onSelect: function (payload) {
                            var files = Array.isArray(payload) ? payload : (payload && payload.files ? payload.files : [payload]);

                            for (var i = 0; i < files.length; i++) {
                                var file = files[i];
                                if (!file) continue;

                                var url = file.url || '';
                                var name = file.basename || file.name || file.path || '';

                                if (/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i.test(name)) {
                                    editor.insertHtml('<img src="' + CKEDITOR.tools.htmlEncode(url) + '" alt="' + CKEDITOR.tools.htmlEncode(name) + '" />');
                                } else {
                                    editor.insertHtml('<a href="' + CKEDITOR.tools.htmlEncode(url) + '">' + CKEDITOR.tools.htmlEncode(name) + '</a>');
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
                icon: this.path + 'icons/fluxfiles.png'
            });
        }
    });
})();
