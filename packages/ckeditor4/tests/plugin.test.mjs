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
  globalThis.CKEDITOR = {
    tools: { htmlEncode: (s) => String(s).replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c])) },
    plugins: { add: (_name, def) => { globalThis.__pluginDef = def; } },
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
});
