import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const pluginCode = readFileSync(join(here, '..', 'plugin.js'), 'utf8');

// Minimal jQuery + Summernote stub. The plugin registers into $.summernote.plugins
// and builds a toolbar button via $.summernote.ui.button().
function makeJQuery() {
  const $ = function () {};
  $.extend = Object.assign;
  $.summernote = {
    plugins: {},
    ui: { button: (def) => ({ def, render: () => ({ __button: def }) }) },
  };
  return $;
}

function loadPlugin({ withSummernote = true } = {}) {
  globalThis.FluxFiles = { open: vi.fn() };
  const $ = withSummernote ? makeJQuery() : (() => { const j = function () {}; j.extend = Object.assign; return j; })();
  globalThis.window = { jQuery: $ };
  // eslint-disable-next-line no-eval
  (0, eval)(pluginCode);

  if (!withSummernote) return { $, open: globalThis.FluxFiles.open };

  const pluginFn = $.summernote.plugins.fluxfiles;
  // Drive the plugin like Summernote would: call the factory with a context,
  // collect the button the plugin memoizes, then render it.
  const memos = {};
  const editorInvoke = vi.fn();
  const context = {
    options: { fluxfiles: { endpoint: 'http://localhost', token: 'JWT' } },
    memo: (name, fn) => { memos[name] = fn; },
    invoke: editorInvoke,
  };
  pluginFn.call({}, context);
  const buttonEl = memos['button.fluxfiles'] && memos['button.fluxfiles']();
  return { $, context, memos, buttonEl, editorInvoke, open: globalThis.FluxFiles.open };
}

// Returns the HTML passed to editor.pasteHTML on the first insert.
function pastedHtml(editorInvoke) {
  const call = editorInvoke.mock.calls.find((c) => c[0] === 'editor.pasteHTML');
  return call ? call[1] : '';
}

describe('Summernote FluxFiles plugin', () => {
  beforeEach(() => { delete globalThis.window; delete globalThis.FluxFiles; });

  it('registers into $.summernote.plugins and memoizes a toolbar button', () => {
    const { $, memos, buttonEl } = loadPlugin();
    expect(typeof $.summernote.plugins.fluxfiles).toBe('function');
    expect(typeof memos['button.fluxfiles']).toBe('function');
    expect(buttonEl).toBeTruthy();
    expect(typeof buttonEl.__button.click).toBe('function');
  });

  it('button click opens FluxFiles with the configured endpoint/token', () => {
    const { buttonEl, open } = loadPlugin();
    buttonEl.__button.click();
    expect(open).toHaveBeenCalled();
    const cfg = open.mock.calls[0][0];
    expect(cfg.endpoint).toBe('http://localhost');
    expect(cfg.token).toBe('JWT');
    expect(cfg.mode).toBe('picker');
  });

  it('onSelect inserts an <img> via editor.pasteHTML', () => {
    const { buttonEl, editorInvoke, open } = loadPlugin();
    buttonEl.__button.click();
    const cfg = open.mock.calls[0][0];
    cfg.onSelect({ url: 'https://cdn/a.png', name: 'a.png', type: 'image' });
    expect(pastedHtml(editorInvoke)).toContain('<img src="https://cdn/a.png"');
  });

  it('uses meta.alt_text for alt and adds width/height from the payload', () => {
    const { buttonEl, editorInvoke, open } = loadPlugin();
    buttonEl.__button.click();
    open.mock.calls[0][0].onSelect({ url: 'https://cdn/a.png', name: 'a.png', mime: 'image/png', width: 800, height: 600, meta: { alt_text: 'A sunset' } });
    const html = pastedHtml(editorInvoke);
    expect(html).toContain('alt="A sunset"');
    expect(html).toContain('width="800"');
    expect(html).toContain('height="600"');
  });

  it('detects images by MIME even without an image extension', () => {
    const { buttonEl, editorInvoke, open } = loadPlugin();
    buttonEl.__button.click();
    open.mock.calls[0][0].onSelect({ url: 'https://cdn/photo', name: 'photo', mime: 'image/jpeg' });
    expect(pastedHtml(editorInvoke)).toMatch(/^<img /);
  });

  it('inserts a non-image as an <a> link using meta.title', () => {
    const { buttonEl, editorInvoke, open } = loadPlugin();
    buttonEl.__button.click();
    open.mock.calls[0][0].onSelect({ url: 'https://cdn/report.pdf', name: 'report.pdf', mime: 'application/pdf', meta: { title: 'Q1 Report' } });
    const html = pastedHtml(editorInvoke);
    expect(html).toContain('<a href="https://cdn/report.pdf"');
    expect(html).toContain('>Q1 Report</a>');
  });

  it('warns when inserting a presigned (expiring) URL', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { buttonEl, open } = loadPlugin();
    buttonEl.__button.click();
    open.mock.calls[0][0].onSelect({ url: 'https://s3/a.png?X-Amz-Signature=abc', name: 'a.png', mime: 'image/png' });
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });

  it('prefers permanent_url over a presigned url (and does not warn)', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { buttonEl, editorInvoke, open } = loadPlugin();
    buttonEl.__button.click();
    open.mock.calls[0][0].onSelect({ url: 'https://s3/a.png?X-Amz-Signature=x', permanent_url: 'https://cdn/a.png', name: 'a.png', mime: 'image/png' });
    const html = pastedHtml(editorInvoke);
    expect(html).toContain('https://cdn/a.png');
    expect(html).not.toContain('X-Amz-');
    expect(warn).not.toHaveBeenCalled();
    warn.mockRestore();
  });

  it('skips folders (is_dir)', () => {
    const { buttonEl, editorInvoke, open } = loadPlugin();
    buttonEl.__button.click();
    open.mock.calls[0][0].onSelect({ name: 'folder', is_dir: true });
    expect(editorInvoke).not.toHaveBeenCalled();
  });

  it('bails quietly (logs, no throw) when Summernote is absent', () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => loadPlugin({ withSummernote: false })).not.toThrow();
    expect(err).toHaveBeenCalled();
    err.mockRestore();
  });
});
