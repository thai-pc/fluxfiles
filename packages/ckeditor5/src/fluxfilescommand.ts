import { Command } from 'ckeditor5';
import type { Editor } from 'ckeditor5';
import FluxFilesSdk from 'fluxfiles';
import type { FluxFile, FluxFilesOpenOptions, FluxFilesSDK } from 'fluxfiles';
import type { FluxFilesConfig } from './config';
import { fileInfo, selectedFiles } from './convert';

/** Prefer a page-level SDK (script tag), else the bundled `fluxfiles` dependency. */
function sdk(): FluxFilesSDK {
  const g = (globalThis as { FluxFiles?: FluxFilesSDK }).FluxFiles;
  return g && typeof g.open === 'function' ? g : (FluxFilesSdk as FluxFilesSDK);
}

/**
 * Opens the FluxFiles picker and inserts the selection at the caret.
 * Images become image elements, anything else a link with the file name as text.
 */
export class FluxFilesCommand extends Command {
  constructor(editor: Editor) {
    super(editor);
    this.affectsData = true;
  }

  private get _cfg(): FluxFilesConfig {
    return ((this.editor.config.get as (k: string) => unknown)('fluxfiles') || {}) as FluxFilesConfig;
  }

  public override refresh(): void {
    this.isEnabled = true;
  }

  /** Resolves once the picker has been opened (not when a file is chosen). */
  public override async execute(): Promise<void> {
    const cfg = this._cfg;
    // The token is only ever handed to the SDK, which posts it to the iframe via
    // postMessage after checking the iframe's origin. It never goes into a URL.
    const token = typeof cfg.token === 'function' ? await cfg.token() : cfg.token || '';
    // `path` is accepted by the SDK at runtime but missing from its typings.
    const options: FluxFilesOpenOptions & { path?: string } = {
      endpoint: cfg.endpoint || '',
      token,
      disk: cfg.disk || 'local',
      mode: 'picker',
      multiple: !!cfg.multiple,
      onSelect: (payload) => this._insert(selectedFiles(payload)),
    };
    if (cfg.disks) options.disks = cfg.disks;
    if (cfg.path) options.path = cfg.path;
    if (cfg.locale) options.locale = cfg.locale;
    if (cfg.theme) options.theme = cfg.theme;
    if (cfg.allowedTypes) options.allowedTypes = cfg.allowedTypes;
    if (cfg.maxUploadMb) options.maxUploadMb = cfg.maxUploadMb;
    if (cfg.maxFiles) options.maxFiles = cfg.maxFiles;
    if (cfg.onTokenRefresh) options.onTokenRefresh = cfg.onTokenRefresh;
    sdk().open(options);
  }

  /** Show a refusal to the editor user (Notification plugin when loaded, else alert). */
  private _notify(messages: string[]): void {
    if (!messages.length) return;
    // One combined message per selection (at most 5 lines). The Notification
    // plugin shows plain text, so file names need no escaping.
    const lines = messages.slice(0, 5);
    if (messages.length > 5) lines.push('… and ' + (messages.length - 5) + ' more');
    const message = lines.join('\n');
    const { plugins } = this.editor;
    if (plugins.has('Notification')) {
      (plugins.get('Notification') as { showWarning(m: string, o?: object): void }).showWarning(message, { namespace: 'fluxfiles' });
    } else if (typeof window.alert === 'function') {
      window.alert(message);
    }
  }

  private _insert(files: FluxFile[]): void {
    const { editor } = this;
    const schema = editor.model.schema;
    const inserted: FluxFile[] = [];
    const refused: string[] = [];

    for (const file of files) {
      const info = fileInfo(file, this._cfg.endpoint, (m) => refused.push(m));
      if (!info.embeddable) continue; // preview-only: already warned

      if (info.isImage && editor.commands.get('insertImage')) {
        const source = { src: info.url, alt: info.alt };
        editor.execute('insertImage', { source: [source] });
        this._leaveImage();
      } else {
        editor.model.change((writer) => {
          const attrs: Record<string, string> = {};
          if (schema.checkAttribute(['$text'], 'linkHref')) attrs.linkHref = info.url;
          // Separate consecutive inserts so several links don't fuse into one word.
          if (inserted.length) editor.model.insertContent(writer.createText(' '));
          editor.model.insertContent(writer.createText(info.title, attrs));
        });
      }
      inserted.push(file);
    }

    this._notify(refused);

    if (inserted.length) {
      editor.editing.view.focus();
      this._cfg.onInsert?.(inserted);
    }
  }

  /**
   * A freshly inserted image stays selected, so the next insert would replace it.
   * Move the caret past it: after an inline image, or into a new paragraph under
   * a block image (the selection post-fixer pulls a root-level caret back onto it).
   */
  private _leaveImage(): void {
    const { model } = this.editor;
    const selected = model.document.selection.getSelectedElement();
    if (!selected) return;
    model.change((writer) => {
      if (selected.name === 'imageInline') {
        writer.setSelection(selected, 'after');
      } else if (selected.name === 'imageBlock' && model.schema.isRegistered('paragraph')) {
        const paragraph = writer.createElement('paragraph');
        writer.insert(paragraph, selected, 'after');
        writer.setSelection(paragraph, 'in');
      }
    });
  }
}
