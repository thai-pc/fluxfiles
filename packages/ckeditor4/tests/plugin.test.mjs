import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const pluginCode = readFileSync(join(here, '..', 'plugin.js'), 'utf8');

/** Load the plugin against mocked CKEDITOR + FluxFiles globals; return the captured editor wiring. */
function loadPlugin() {
  const commands = {};
  const buttons = {};
  globalThis.FluxFiles = { open: vi.fn() };
  globalThis.__ckOn = {};
  globalThis.CKEDITOR = {
    tools: { htmlEncode: (s) => String(s).replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c])) },
    plugins: { add: (_name, def) => { globalThis.__pluginDef = def; } },
    // Capture the global dialogDefinition listener the plugin registers once.
    on: (evt, handler) => { globalThis.__ckOn[evt] = handler; },
  };
  // eslint-disable-next-line no-eval
  (0, eval)(pluginCode);
  const editor = {
    config: { fluxfiles: { endpoint: 'http://localhost', token: 'JWT' } },
    addCommand: (name, def) => { commands[name] = def; },
    ui: { addButton: (name, def) => { buttons[name] = def; } },
    insertHtml: vi.fn(),
    execCommand: (name) => commands[name].exec(),
  };
  globalThis.__pluginDef.init(editor);
  return { editor, commands, buttons, open: globalThis.FluxFiles.open };
}

describe('CKEditor 4 FluxFiles plugin', () => {
  beforeEach(() => { delete globalThis.__pluginDef; });

  it('registers the plugin + a toolbar button + the openFluxFiles command', () => {
    const { commands, buttons } = loadPlugin();
    expect(globalThis.__pluginDef).toBeTruthy();
    expect(commands.openFluxFiles).toBeTruthy();
    expect(buttons.FluxFiles).toBeTruthy();
  });

  it('toolbar icon is an inline SVG data URI (no external PNG file)', () => {
    const { buttons } = loadPlugin();
    const icon = buttons.FluxFiles.icon;
    expect(icon.startsWith('data:image/svg+xml,')).toBe(true);
    expect(decodeURIComponent(icon)).toContain('<svg');
    expect(icon).not.toContain('.png'); // the old icons/fluxfiles.png is gone
  });

  it('running the command opens FluxFiles with the editor config', () => {
    const { editor, open } = loadPlugin();
    editor.execCommand('openFluxFiles');
    expect(open).toHaveBeenCalled();
    const cfg = open.mock.calls[0][0];
    expect(cfg.endpoint).toBe('http://localhost');
    expect(cfg.token).toBe('JWT');
    expect(typeof cfg.onSelect).toBe('function');
  });

  it('onSelect inserts an <img> with the selected URL', () => {
    const { editor, open } = loadPlugin();
    editor.execCommand('openFluxFiles');
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://cdn/a.png', name: 'a.png', type: 'image' });
    expect(editor.insertHtml).toHaveBeenCalled();
    expect(editor.insertHtml.mock.calls[0][0]).toContain('<img src="https://cdn/a.png"');
  });

  it('uses meta.alt_text for alt and adds width/height from the payload', () => {
    const { editor, open } = loadPlugin();
    editor.execCommand('openFluxFiles');
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://cdn/a.png', name: 'a.png', mime: 'image/png', width: 800, height: 600, meta: { alt_text: 'A sunset' } });
    const html = editor.insertHtml.mock.calls[0][0];
    expect(html).toContain('alt="A sunset"');
    expect(html).toContain('width="800"');
    expect(html).toContain('height="600"');
  });

  it('detects images by MIME even without an image extension', () => {
    const { editor, open } = loadPlugin();
    editor.execCommand('openFluxFiles');
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://cdn/photo', name: 'photo', mime: 'image/jpeg' });
    expect(editor.insertHtml.mock.calls[0][0]).toMatch(/^<img /);
  });

  it('warns when inserting a presigned (expiring) URL', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { editor, open } = loadPlugin();
    editor.execCommand('openFluxFiles');
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://s3/a.png?X-Amz-Signature=abc', name: 'a.png', mime: 'image/png' });
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });

  it('skips folders (is_dir)', () => {
    const { editor, open } = loadPlugin();
    editor.execCommand('openFluxFiles');
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ name: 'folder', is_dir: true });
    expect(editor.insertHtml).not.toHaveBeenCalled();
  });

  it('prefers permanent_url over a presigned url (and does not warn)', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { editor, open } = loadPlugin();
    editor.execCommand('openFluxFiles');
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://s3/a.png?X-Amz-Signature=x', permanent_url: 'https://cdn/a.png', name: 'a.png', mime: 'image/png' });
    const html = editor.insertHtml.mock.calls[0][0];
    expect(html).toContain('https://cdn/a.png');
    expect(html).not.toContain('X-Amz-');
    expect(warn).not.toHaveBeenCalled();
    warn.mockRestore();
  });

  // ── Native Image dialog: "Browse FluxFiles" button ─────────────────────
  // Build a fake image-dialog definition and run the captured listener.
  function runImageDialogDef(editor) {
    const added = [];
    const definition = {
      getContents: (id) => (id === 'info' ? { add: (el) => added.push(el) } : null),
    };
    globalThis.__ckOn.dialogDefinition({ data: { name: 'image', definition }, editor });
    return added;
  }

  it('registers a dialogDefinition listener and adds a Browse FluxFiles button to the image dialog', () => {
    const { editor } = loadPlugin();
    expect(typeof globalThis.__ckOn.dialogDefinition).toBe('function');
    const added = runImageDialogDef(editor);
    const btn = added.find((e) => e.id === 'fluxfilesBrowse');
    expect(btn).toBeTruthy();
    expect(btn.type).toBe('button');
    expect(typeof btn.onClick).toBe('function');
  });

  it('Browse FluxFiles fills url/alt/width/height of the native dialog', () => {
    const { editor, open } = loadPlugin();
    const btn = runImageDialogDef(editor).find((e) => e.id === 'fluxfilesBrowse');
    const values = {};
    const dialog = {
      getContentElement: (_tab, id) => ({ setValue: (v) => { values[id] = v; }, getValue: () => values[id] }),
    };
    btn.onClick.call({ getDialog: () => dialog });
    expect(open).toHaveBeenCalled();
    open.mock.calls[0][0].onSelect({ url: 'https://cdn/a.png', name: 'a.png', mime: 'image/png', width: 800, height: 600, meta: { alt_text: 'A sunset' } });
    expect(values.txtUrl).toBe('https://cdn/a.png');
    expect(values.txtAlt).toBe('A sunset');
    expect(values.txtWidth).toBe('800');
    expect(values.txtHeight).toBe('600');
  });

  it('does not add the dialog button when config.fluxfiles.imageDialog === false', () => {
    const { editor } = loadPlugin();
    editor.config.fluxfiles.imageDialog = false;
    const added = runImageDialogDef(editor);
    expect(added.find((e) => e.id === 'fluxfilesBrowse')).toBeFalsy();
  });
});
