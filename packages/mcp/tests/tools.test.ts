import { describe, it, expect } from 'vitest';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { FluxFilesClient, buildTools, createServer } from '../src/index.js';

type Call = { method: string; url: string; headers: Record<string, string>; body?: unknown };

/** A fake fetch that routes `/api/fm/<path>` to handlers and records every call. */
function fakeFetch(routes: Record<string, (c: Call) => { status?: number; body: unknown }>) {
  const calls: Call[] = [];
  const f = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    const c: Call = { method: init?.method ?? 'GET', url, headers: (init?.headers ?? {}) as Record<string, string>, body: init?.body };
    calls.push(c);
    const path = new URL(url).pathname.replace(/^.*\/api\/fm/, '');
    const h = routes[`${c.method} ${path}`];
    const r = h ? h(c) : { status: 404, body: { data: null, error: 'Not found', error_code: 'not_found' } };
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } });
  }) as typeof fetch;
  return { f, calls };
}
const ok = (data: unknown) => ({ body: { data, error: null } });
const opts = { disk: 'local', allowWrite: false, allowDelete: false, maxTextChars: 10 };
const byName = (tools: ReturnType<typeof buildTools>, n: string) => tools.find((t) => t.name === n)!;

describe('tools', () => {
  it('is read-only unless writes are enabled; delete needs both flags', () => {
    const c = new FluxFilesClient({ baseUrl: 'https://f.test', token: 't' });
    expect(buildTools(c, opts).map((t) => t.name)).toEqual(
      ['list_files', 'search_files', 'read_text_file', 'get_file_info', 'get_download_link', 'storage_usage']);
    expect(buildTools(c, { ...opts, allowWrite: true }).map((t) => t.name)).toContain('write_text_file');
    expect(buildTools(c, { ...opts, allowWrite: true }).map((t) => t.name)).not.toContain('move_to_trash');
    expect(buildTools(c, { ...opts, allowWrite: true, allowDelete: true }).map((t) => t.name)).toContain('move_to_trash');
  });

  it('list_files sends the bearer token and slims items', async () => {
    const { f, calls } = fakeFetch({ 'GET /list': () => ok({ items: [
      { key: 'docs/a.pdf', name: 'a.pdf', type: 'file', size: 3, modified: 1_700_000_000, meta: { title: 'A' } }] }) });
    const t = byName(buildTools(new FluxFilesClient({ baseUrl: 'https://f.test/', token: 'jwt-1', fetch: f }), opts), 'list_files');
    const r = (await t.run({ path: 'docs' })) as { items: Array<Record<string, unknown>> };
    expect(calls[0].headers.Authorization).toBe('Bearer jwt-1');
    expect(calls[0].url).toBe('https://f.test/api/fm/list?disk=local&path=docs&limit=500');
    expect(r.items[0]).toMatchObject({ path: 'docs/a.pdf', title: 'A', modified: '2023-11-14T22:13:20.000Z' });
  });

  it('read_text_file truncates to maxTextChars and says so', async () => {
    const { f } = fakeFetch({ 'GET /content': () => ok({ content: '0123456789ABCDEF', size: 16 }) });
    const r = await byName(buildTools(new FluxFilesClient({ baseUrl: 'https://f.test', token: 't', fetch: f }), opts), 'read_text_file').run({ path: 'x.txt' });
    expect(r).toMatchObject({ truncated: true, content: '0123456789' });
  });

  it('get_download_link falls back to the listing URL on a public local disk', async () => {
    const { f } = fakeFetch({
      'POST /presign': () => ({ status: 400, body: { data: null, error: 'Presigned URLs are only available for S3/R2 disks' } }),
      'GET /list': () => ok([{ key: 'pics/a.png', url: 'https://f.test/storage/uploads/pics/a.png' }]),
    });
    const r = await byName(buildTools(new FluxFilesClient({ baseUrl: 'https://f.test', token: 't', fetch: f }), opts), 'get_download_link').run({ path: 'pics/a.png' });
    expect(r).toEqual({ url: 'https://f.test/storage/uploads/pics/a.png', expires_at: null });
  });

  it('write_text_file uploads a new file, or PUTs the content of an existing one', async () => {
    const created = fakeFetch({ 'POST /upload': () => ok({ key: 'notes/n.md' }) });
    const w1 = byName(buildTools(new FluxFilesClient({ baseUrl: 'https://f.test', token: 't', fetch: created.f }), { ...opts, allowWrite: true }), 'write_text_file');
    await w1.run({ path: 'notes/n.md', content: '# hi' });
    const up = created.calls.find((c) => c.url.endsWith('/upload'))!;
    expect(up.body).toBeInstanceOf(FormData);
    expect((up.body as FormData).get('path')).toBe('notes');

    const existing = fakeFetch({ 'GET /meta': () => ok({ size: 1 }), 'PUT /content': () => ok({ path: 'n.md', size: 4 }) });
    await byName(buildTools(new FluxFilesClient({ baseUrl: 'https://f.test', token: 't', fetch: existing.f }), { ...opts, allowWrite: true }), 'write_text_file')
      .run({ path: 'n.md', content: 'new!' });
    expect(existing.calls.map((c) => `${c.method} ${new URL(c.url).pathname}`)).toEqual(['GET /api/fm/meta', 'PUT /api/fm/content']);
  });
});

describe('MCP server (in-memory transport)', () => {
  it('lists tools with annotations and turns API errors into isError results', async () => {
    const { f } = fakeFetch({ 'GET /content': () => ({ status: 403, body: { data: null, error: 'Access denied to path', error_code: 'path_denied' } }) });
    const server = createServer({ baseUrl: 'https://f.test', token: 't', fetch: f });
    const [a, b] = InMemoryTransport.createLinkedPair();
    const client = new Client({ name: 'test', version: '0' });
    await Promise.all([server.connect(a), client.connect(b)]);

    const { tools } = await client.listTools();
    const read = tools.find((t) => t.name === 'read_text_file')!;
    expect(read.annotations?.readOnlyHint).toBe(true);
    expect(read.inputSchema.required).toContain('path');

    const res = await client.callTool({ name: 'read_text_file', arguments: { path: '../secret' } });
    expect(res.isError).toBe(true);
    expect((res.content as Array<{ text: string }>)[0].text).toBe('Access denied to path (path_denied)');
  });
});
