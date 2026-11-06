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

  private _insert(files: FluxFile[]): void {
    const { editor } = this;
    const schema = editor.model.schema;
    const inserted: FluxFile[] = [];

    for (const file of files) {
      const info = fileInfo(file);
      if (!info.embeddable) continue; // preview-only: already warned

      if (info.isImage && editor.commands.get('insertImage')) {
        const source: Record<string, unknown> = { src: info.url, alt: info.alt };
        // Responsive attributes only when the image plugin's schema knows them
        // (core ImageEditing registers `srcset` as a plain string; it has no `sizes`
        // model attribute and always writes sizes="100vw", so img_sizes is only
        // honoured by a custom image plugin that registers one).
        const type = schema.isRegistered('imageBlock') ? 'imageBlock' : 'imageInline';
        if (info.srcset && schema.checkAttribute(type, 'srcset')) source.srcset = info.srcset;
        if (info.sizes && schema.checkAttribute(type, 'sizes')) source.sizes = info.sizes;
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
