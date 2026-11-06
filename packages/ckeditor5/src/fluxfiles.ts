import { Plugin, ButtonView } from 'ckeditor5';
import type { Locale } from 'ckeditor5';
import type { FluxFilesConfig } from './config';
import { FluxFilesCommand } from './fluxfilescommand';

// The same F-folder glyph the TinyMCE and CKEditor 4 plugins use. currentColor
// lets CKEditor 5 recolour it for hover / disabled / dark themes.
const ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">' +
  '<path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" ' +
  'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
  '<path d="M10 16.5v-6h4.5M10 13.5h3.5" ' +
  'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

/** Minimal shape of the optional ImageInsertUI plugin (CKEditor's image package). */
interface ImageInsertUiLike {
  registerIntegration(def: {
    name: string;
    observable: unknown;
    buttonViewCreator: (isOnlyOne: boolean) => ButtonView;
    formViewCreator: (isOnlyOne: boolean) => unknown;
    requiresForm?: boolean;
  }): void;
}

/**
 * FluxFiles for CKEditor 5.
 *
 * - command `fluxfiles`, toolbar item `fluxfiles`
 * - an "Insert image via FluxFiles" entry in the image insert dropdown when the
 *   image insert UI is loaded (disable with `fluxfiles.imageInsert = false`)
 *
 * Configured under `config.fluxfiles`.
 */
export class FluxFiles extends Plugin {
  public static get pluginName() {
    return 'FluxFiles' as const;
  }

  public init(): void {
    const editor = this.editor;
    editor.commands.add('fluxfiles', new FluxFilesCommand(editor));
    editor.ui.componentFactory.add('fluxfiles', (locale) => this._createButton(locale, true));
  }

  public afterInit(): void {
    const editor = this.editor;
    const cfg = ((editor.config.get as (k: string) => unknown)('fluxfiles') || {}) as FluxFilesConfig;
    if (cfg.imageInsert === false || !editor.plugins.has('ImageInsertUI')) return;

    const ui = editor.plugins.get('ImageInsertUI') as unknown as ImageInsertUiLike;
    ui.registerIntegration({
      name: 'fluxfiles',
      observable: editor.commands.get('fluxfiles'),
      buttonViewCreator: () => {
        const button = this._createButton(editor.locale, false);
        button.label = editor.locale.t('Insert image via FluxFiles');
        button.tooltip = true;
        return button;
      },
      formViewCreator: () => this._createButton(editor.locale, false),
      requiresForm: false,
    });
  }

  private _createButton(locale: Locale, withTooltip: boolean): ButtonView {
    const editor = this.editor;
    const command = editor.commands.get('fluxfiles')!;
    const button = new ButtonView(locale);
    button.set({ label: editor.locale.t('FluxFiles'), icon: ICON, tooltip: withTooltip });
    button.bind('isEnabled').to(command, 'isEnabled');
    this.listenTo(button, 'execute', () => {
      editor.execute('fluxfiles');
      editor.editing.view.focus();
    });
    return button;
  }
}
