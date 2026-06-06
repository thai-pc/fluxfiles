import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const pluginCode = readFileSync(join(here, '..', 'plugin.js'), 'utf8');

function loadPlugin() {
  globalThis.FluxFiles = { open: vi.fn() };
  globalThis.tinymce = {
    majorVersion: '6',
    PluginManager: { add: (_name, cb) => { globalThis.__tmceCb = cb; } },
  };
  // eslint-disable-next-line no-eval
  (0, eval)(pluginCode);

  const buttons = {}, menuitems = {};
  const editor = {
    getParam: (k, d) => ({ fluxfiles_endpoint: 'http://localhost', fluxfiles_token: 'JWT' }[k] ?? d),
    ui: { registry: {
      addIcon: vi.fn(),
      addButton: (name, def) => { buttons[name] = def; },
      addMenuItem: (name, def) => { menuitems[name] = def; },
    } },
    insertContent: vi.fn(),
  };
  globalThis.__tmceCb(editor);
  return { editor, buttons, menuitems, open: globalThis.FluxFiles.open };
}

describe('TinyMCE FluxFiles plugin', () => {
  beforeEach(() => { delete globalThis.__tmceCb; });

  it('registers via PluginManager + a toolbar button', () => {
    const { buttons } = loadPlugin();
    expect(globalThis.__tmceCb).toBeTruthy();
    expect(buttons.fluxfiles).toBeTruthy();
    expect(typeof buttons.fluxfiles.onAction).toBe('function');
  });

  it('button onAction opens FluxFiles with the configured endpoint/token', () => {
    const { buttons, open } = loadPlugin();
    buttons.fluxfiles.onAction();
    expect(open).toHaveBeenCalled();
    const cfg = open.mock.calls[0][0];
    expect(cfg.endpoint).toBe('http://localhost');
    expect(cfg.token).toBe('JWT');
  });

  it('onSelect inserts an <img> with the selected URL', () => {
    const { editor, buttons, open } = loadPlugin();
    buttons.fluxfiles.onAction();
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://cdn/a.png', name: 'a.png', type: 'image' });
    expect(editor.insertContent).toHaveBeenCalled();
    expect(editor.insertContent.mock.calls[0][0]).toContain('<img src="https://cdn/a.png"');
  });

  it('uses meta.alt_text for alt and adds width/height from the payload', () => {
    const { editor, buttons, open } = loadPlugin();
    buttons.fluxfiles.onAction();
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://cdn/a.png', name: 'a.png', mime: 'image/png', width: 800, height: 600, meta: { alt_text: 'A sunset' } });
    const html = editor.insertContent.mock.calls[0][0];
    expect(html).toContain('alt="A sunset"');
    expect(html).toContain('width="800"');
    expect(html).toContain('height="600"');
  });

  it('detects images by MIME even without an image extension', () => {
    const { editor, buttons, open } = loadPlugin();
    buttons.fluxfiles.onAction();
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://cdn/photo', name: 'photo', mime: 'image/jpeg' });
    expect(editor.insertContent.mock.calls[0][0]).toMatch(/^<img /);
  });

  it('warns when inserting a presigned (expiring) URL', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { editor, buttons, open } = loadPlugin();
    buttons.fluxfiles.onAction();
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://s3/a.png?X-Amz-Signature=abc', name: 'a.png', mime: 'image/png' });
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });

  it('skips folders (is_dir)', () => {
    const { editor, buttons, open } = loadPlugin();
    buttons.fluxfiles.onAction();
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ name: 'folder', is_dir: true });
    expect(editor.insertContent).not.toHaveBeenCalled();
  });

  it('prefers permanent_url over a presigned url (and does not warn)', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { editor, buttons, open } = loadPlugin();
    buttons.fluxfiles.onAction();
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://s3/a.png?X-Amz-Signature=x', permanent_url: 'https://cdn/a.png', name: 'a.png', mime: 'image/png' });
    const html = editor.insertContent.mock.calls[0][0];
    expect(html).toContain('https://cdn/a.png');
    expect(html).not.toContain('X-Amz-');
    expect(warn).not.toHaveBeenCalled();
    warn.mockRestore();
  });
});
