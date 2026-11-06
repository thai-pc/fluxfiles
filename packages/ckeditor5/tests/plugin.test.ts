import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ClassicEditor, Essentials, Paragraph, Image, ImageInsert, Link } from 'ckeditor5';
import { FluxFiles, fileInfo, selectedFiles } from '../src/index';

let openMock: ReturnType<typeof vi.fn>;
let host: HTMLElement;
const editors: ClassicEditor[] = [];

async function make(fluxfiles: Record<string, unknown> = {}, plugins: unknown[] = [Image, ImageInsert, Link]) {
  const editor = await ClassicEditor.create(host, {
    licenseKey: 'GPL',
    plugins: [Essentials, Paragraph, FluxFiles, ...plugins] as never,
    toolbar: ['fluxfiles'],
    fluxfiles: { endpoint: 'http://localhost:8080', token: 'JWT', ...fluxfiles },
  } as never);
  editors.push(editor);
  return editor;
}

/** Run the command and hand the picker's onSelect callback back. */
async function pick(editor: ClassicEditor, payload: unknown) {
  await editor.execute('fluxfiles');
  const opts = openMock.mock.calls[openMock.mock.calls.length - 1][0];
  opts.onSelect(payload);
  return opts;
}

beforeEach(() => {
  openMock = vi.fn();
  (globalThis as { FluxFiles?: unknown }).FluxFiles = { open: openMock };
  host = document.createElement('div');
  document.body.appendChild(host);
});

afterEach(async () => {
  while (editors.length) await editors.pop()!.destroy();
  host.remove();
  delete (globalThis as { FluxFiles?: unknown }).FluxFiles;
  vi.restoreAllMocks();
});

describe('registration', () => {
  it('registers the plugin, command and toolbar button', async () => {
    const editor = await make();
    expect(FluxFiles.pluginName).toBe('FluxFiles');
    expect(editor.plugins.get('FluxFiles')).toBeInstanceOf(FluxFiles);
    expect(editor.commands.get('fluxfiles')).toBeTruthy();
    expect(editor.ui.componentFactory.has('fluxfiles')).toBe(true);
    const button = editor.ui.componentFactory.create('fluxfiles') as { label: string; isEnabled: boolean };
    expect(button.label).toBe('FluxFiles');
    expect(button.isEnabled).toBe(true);
  });

  it('clicking the button opens the picker', async () => {
    const editor = await make();
    const button = editor.ui.componentFactory.create('fluxfiles');
    button.fire('execute');
    await Promise.resolve();
    expect(openMock).toHaveBeenCalledTimes(1);
  });

  it('adds an entry to the image insert dropdown, unless disabled', async () => {
    const withUi = await make();
    const ui = withUi.plugins.get('ImageInsertUI') as unknown as { _integrations: Map<string, unknown> };
    expect(ui._integrations.has('fluxfiles')).toBe(true);
    // Registered alone is invisible: ImageInsertUI renders only listed integrations.
    expect(withUi.config.get('image.insert.integrations')).toContain('fluxfiles');
    await withUi.destroy();
    editors.pop();

    const without = await make({ imageInsert: false });
    const ui2 = without.plugins.get('ImageInsertUI') as unknown as { _integrations: Map<string, unknown> };
    expect(ui2._integrations.has('fluxfiles')).toBe(false);
  });
});

describe('opening the picker', () => {
  it('passes the configured options to FluxFiles.open', async () => {
    const onTokenRefresh = vi.fn(async () => 'NEW');
    const editor = await make({
      disk: 's3', disks: ['s3', 'local'], path: 'uploads', locale: 'vi', theme: 'dark',
      multiple: true, allowedTypes: ['image'], maxUploadMb: 5, maxFiles: 3, onTokenRefresh,
    });
    await editor.execute('fluxfiles');
    const o = openMock.mock.calls[0][0];
    expect(o).toMatchObject({
      endpoint: 'http://localhost:8080', token: 'JWT', disk: 's3', disks: ['s3', 'local'],
      path: 'uploads', locale: 'vi', theme: 'dark', mode: 'picker', multiple: true,
      allowedTypes: ['image'], maxUploadMb: 5, maxFiles: 3,
    });
    expect(o.onTokenRefresh).toBe(onTokenRefresh);
  });

  it('defaults to the local disk, single select, no optional keys', async () => {
    const editor = await make();
    await editor.execute('fluxfiles');
    const o = openMock.mock.calls[0][0];
    expect(o.disk).toBe('local');
    expect(o.multiple).toBe(false);
    expect('onTokenRefresh' in o).toBe(false);
    expect('locale' in o).toBe(false);
  });

  it('resolves a token getter (sync or async) on every open', async () => {
    let n = 0;
    const editor = await make({ token: async () => 'T' + ++n });
    await editor.execute('fluxfiles');
    await editor.execute('fluxfiles');
    expect(openMock.mock.calls[0][0].token).toBe('T1');
    expect(openMock.mock.calls[1][0].token).toBe('T2');
  });

  it('never puts the token in a URL option', async () => {
    const editor = await make({ endpoint: 'https://fm.example.com' });
    await editor.execute('fluxfiles');
    const o = openMock.mock.calls[0][0];
    expect(o.endpoint).not.toContain('JWT');
    expect(o.endpoint).not.toContain('token');
  });

  it('forwards onTokenRefresh so the SDK can answer a 401', async () => {
    const onTokenRefresh = vi.fn(async () => 'FRESH');
    const editor = await make({ onTokenRefresh });
    await editor.execute('fluxfiles');
    const ctx = { reason: 'expired', disk: 'local', path: '' };
    await expect(openMock.mock.calls[0][0].onTokenRefresh(ctx)).resolves.toBe('FRESH');
    expect(onTokenRefresh).toHaveBeenCalledWith(ctx);
  });
});

describe('inserting the selection', () => {
  it('inserts an image with src and alt from metadata', async () => {
    const editor = await make();
    await pick(editor, { name: 'a.png', mime: 'image/png', permanent_url: 'https://cdn/a.png', meta: { alt_text: 'A "cat"' } });
    const html = editor.getData();
    expect(html).toContain('<img');
    expect(html).toContain('src="https://cdn/a.png"');
    expect(html).toContain('alt="A &quot;cat&quot;"');
  });

  it('falls back to the file name for alt, and detects images by extension', async () => {
    const editor = await make();
    await pick(editor, { name: 'dog.JPG', url: 'https://cdn/dog.JPG' });
    expect(editor.getData()).toContain('alt="dog.JPG"');
  });

  it('adds srcset when the payload carries img_srcset', async () => {
    const editor = await make();
    await pick(editor, {
      name: 'a.png', mime: 'image/png', permanent_url: 'https://cdn/a.png',
      img_srcset: 'https://cdn/a-480.webp 480w, https://cdn/a-960.webp 960w', img_sizes: '100vw',
    });
    expect(editor.getData()).toContain('srcset="https://cdn/a-480.webp 480w, https://cdn/a-960.webp 960w"');
  });

  it('inserts a non-image file as a link with the file name as text', async () => {
    const editor = await make();
    await pick(editor, { name: 'report.pdf', mime: 'application/pdf', permanent_url: 'https://cdn/report.pdf' });
    expect(editor.getData()).toContain('<a href="https://cdn/report.pdf">report.pdf</a>');
  });

  it('uses the metadata title as link text when present', async () => {
    const editor = await make();
    await pick(editor, { name: 'r.pdf', permanent_url: 'https://cdn/r.pdf', meta: { title: 'Annual report' } });
    expect(editor.getData()).toContain('>Annual report</a>');
  });

  it('inserts every file of a multiple selection, skipping folders', async () => {
    const editor = await make({ multiple: true });
    await pick(editor, [
      { name: 'a.png', mime: 'image/png', permanent_url: 'https://cdn/a.png' },
      { name: 'dir', is_dir: true, path: 'dir' },
      { name: 'b.pdf', permanent_url: 'https://cdn/b.pdf' },
      { name: 'c.zip', permanent_url: 'https://cdn/c.zip' },
    ]);
    const html = editor.getData();
    expect(html).toContain('src="https://cdn/a.png"');
    expect(html).toContain('href="https://cdn/b.pdf"');
    expect(html).toContain('href="https://cdn/c.zip"');
    expect(html).not.toContain('dir');
  });

  it('refuses a preview-only selection (no embeddable URL)', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const editor = await make();
    const before = editor.getData();
    await pick(editor, { name: 'x.png', mime: 'image/png', img_base: 'https://fm/img?t=tok' });
    expect(editor.getData()).toBe(before);
    expect(warn).toHaveBeenCalled();
  });

  it('falls back to a link for images when no image plugin is loaded', async () => {
    const editor = await make({}, [Link]);
    await pick(editor, { name: 'a.png', mime: 'image/png', permanent_url: 'https://cdn/a.png' });
    const html = editor.getData();
    expect(html).not.toContain('<img');
    expect(html).toContain('<a href="https://cdn/a.png">a.png</a>');
  });

  it('inserts plain text when no link plugin is loaded', async () => {
    const editor = await make({}, []);
    await pick(editor, { name: 'a.pdf', permanent_url: 'https://cdn/a.pdf' });
    expect(editor.getData()).toContain('a.pdf');
    expect(editor.getData()).not.toContain('<a');
  });

  it('calls onInsert with the inserted files', async () => {
    const onInsert = vi.fn();
    const editor = await make({ onInsert });
    const f = { name: 'a.pdf', permanent_url: 'https://cdn/a.pdf' };
    await pick(editor, f);
    expect(onInsert).toHaveBeenCalledWith([f]);
  });
});

describe('convert helpers', () => {
  it('fileInfo prefers permanent_url and flags presigned URLs', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const i = fileInfo({ path: 'p', basename: 'b', url: 'https://s3/x?X-Amz-Signature=1', permanent_url: '' });
    expect(i.url).toContain('X-Amz-');
    expect(warn).toHaveBeenCalled();
    expect(fileInfo({ path: 'p', basename: 'b', url: 'u', permanent_url: 'perm' }).url).toBe('perm');
  });

  it('selectedFiles normalises single, array and empty payloads', () => {
    expect(selectedFiles(null)).toEqual([]);
    expect(selectedFiles({ path: 'a', basename: 'a' })).toHaveLength(1);
    expect(selectedFiles([{ path: 'a', basename: 'a' }, { path: 'd', basename: 'd', is_dir: true }])).toHaveLength(1);
  });
});
