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
});
