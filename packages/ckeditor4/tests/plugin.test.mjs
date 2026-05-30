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
});
