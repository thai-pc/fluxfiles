import { test, expect, type Page } from '@playwright/test';
import { mintToken, imageFile, pngFile, cardByName, createFolder, enterFolder, uploadFile } from './helpers';
import { PORT } from './secret';

// The UI iframe is always served from the CORE origin; the host page that embeds it
// is served from a DIFFERENT origin (host.test vs core.test, same server), the way a
// real integration works. A root-relative url that was not absolutized resolves
// against the host (host.test) and is caught below.
// Both names are fake: requests to them are fetched from the real 127.0.0.1 server by
// the test runner (a browser loopback host framing another loopback host is blocked by
// Chrome's local-network access checks, and fake public-looking names avoid that).
const CORE = `http://core.test:${PORT}`;
const HOST = `http://host.test:${PORT}`;

async function openPicker(page: Page, extra: Record<string, unknown>, route: string) {
  const token = mintToken();
  const host = `<!doctype html><html><body>
    <iframe id="fm" src="${CORE}/public/index.html" style="width:1000px;height:680px;border:0"></iframe>
    <script>
      window.__sels = [];
      window.addEventListener('message', function (e) {
        var m = e.data;
        if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY') {
          document.getElementById('fm').contentWindow.postMessage({
            source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'host-1',
            payload: Object.assign({ token: ${JSON.stringify(token)}, disk: 'local',
              endpoint: ${JSON.stringify(CORE)}, mode: 'picker' }, ${JSON.stringify(extra)})
          }, '*');
        }
        if (m.type === 'FM_SELECT') { window.__sels.push(m.payload); }
      });
    </script></body></html>`;
  await page.route(`${CORE}/**`, async (r) => {
    const resp = await r.fetch({ url: r.request().url().replace('core.test', '127.0.0.1'), headers: { ...r.request().headers(), host: `core.test:${PORT}` } });
    await r.fulfill({ response: resp });
  });
  await page.route(`${HOST}${route}`, (r) => r.fulfill({ contentType: 'text/html', body: host }));
  await page.goto(`${HOST}${route}`);
  expect(new URL(page.url()).origin).toBe(HOST);
  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  const folder = `pw-pt-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  await createFolder(frame, page, folder);
  await enterFolder(frame, folder);
  return frame;
}
test.afterEach(async ({ page }) => { await page.unrouteAll({ behavior: 'ignoreErrors' }); });

const sels = (page: Page) => page.evaluate(() => (window as any).__sels as any[]);
const app = (frame: ReturnType<Page['frameLocator']>) => frame.locator('.ff-app');
const txtFile = (name: string) => ({ name, mimeType: 'text/plain', buffer: Buffer.from('t ' + Math.random()) });

test('cross-origin host: FM_SELECT urls are absolute against the CORE origin (url, variants, img_base, img_srcset)', async ({ page }) => {
  const frame = await openPicker(page, {}, '/__pw_abs');
  const name = `abs-${Date.now()}.png`;
  await uploadFile(frame, imageFile(name, 1200, 800));
  await expect(cardByName(frame, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(frame, name).dblclick();
  await expect.poll(async () => (await sels(page)).length, { timeout: 10_000 }).toBeGreaterThan(0);
  const sel = (await sels(page))[0];
  expect(sel.url.startsWith(`${CORE}/storage/uploads/`), sel.url).toBe(true);
  expect(sel.permanent_url === null || sel.permanent_url.startsWith(`${CORE}/`)).toBe(true);
  for (const v of Object.values<any>(sel.variants || {})) expect(v.url.startsWith(`${CORE}/`), v.url).toBe(true);
  expect(sel.img_base, 'img_base present').toBeTruthy();
  expect(sel.img_base.startsWith(`${CORE}/`), sel.img_base).toBe(true);
  expect(sel.img_srcset, 'img_srcset present').toBeTruthy();
  for (const cand of String(sel.img_srcset).split(',')) expect(cand.trim().startsWith(`${CORE}/`), cand).toBe(true);
});

test('urls that are not http(s) never leave the picker', async ({ page }) => {
  const frame = await openPicker(page, {}, '/__pw_unsafe');
  const res = await app(frame).evaluate((el: any) => {
    const d = (window as any).Alpine.$data(el);
    return ['javascript:alert(1)', ' \tjava\nscript:alert(1)', '\u0001JaVaScRiPt:alert(1)', 'data:text/html,x', 'vbscript:x', 'file:///etc/passwd']
      .map((u) => d._absUrl(u))
      .concat([d._absUrl('//cdn.example.com/a.png'), d._absUrl('/storage/uploads/a.png'), d._absUrl('https://s3.example.com/a.png?X-Amz-Signature=1')]);
  });
  expect(res.slice(0, 6)).toEqual(['', '', '', '', '', '']);
  expect(res[6]).toBe('http://cdn.example.com/a.png'.replace('http:', 'http:')); // protocol-relative follows the iframe scheme (http here)
  expect(res[7]).toBe(`${CORE}/storage/uploads/a.png`);
  expect(res[8]).toBe('https://s3.example.com/a.png?X-Amz-Signature=1');
  await app(frame).evaluate((el: any) => {
    (window as any).Alpine.$data(el).selectFile({ name: 'x.png', key: 'x.png', type: 'file', url: ' javascript:alert(1)' });
  });
  await expect.poll(async () => (await sels(page)).length).toBe(1);
  expect((await sels(page))[0].url).toBe('');
});

test('allowedTypes (picker): accept attr, refused upload names the file, matching file selectable', async ({ page }) => {
  const frame = await openPicker(page, { allowedTypes: ['image/*', '.pdf'] }, '/__pw_types');
  await expect(frame.locator('input[type=file]').first()).toHaveAttribute('accept', 'image/*,.pdf');

  const img = `ok-${Date.now()}.png`;
  await uploadFile(frame, pngFile(img));
  await expect(cardByName(frame, img)).toBeVisible({ timeout: 15_000 });

  // Extension-less file: the toast names the file, it does not print ".README".
  await uploadFile(frame, txtFile('README'));
  const toast = frame.locator('.ff-toast');
  await expect(toast).toContainText('README', { timeout: 10_000 });
  await expect(toast).not.toContainText('.README');
  await expect(cardByName(frame, 'README')).toHaveCount(0);

  await cardByName(frame, img).dblclick();
  await expect.poll(async () => (await sels(page)).length, { timeout: 10_000 }).toBe(1);
});

test('allowedTypes: matcher uses extension when the stored mime is generic or wrong', async ({ page }) => {
  const frame = await openPicker(page, { allowedTypes: ['image/*', 'pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] }, '/__pw_types_unit');
  const res = await app(frame).evaluate((el: any) => {
    const d = (window as any).Alpine.$data(el);
    const a = (f: any) => d._typeAllowed(Object.assign({ type: 'file' }, f));
    return {
      txt: a({ name: 'a.txt' }), png: a({ name: 'a.PNG' }), pdfBare: a({ name: 'a.pdf' }),
      mimeOnly: a({ name: 'blob', mime: 'image/webp' }), dir: d._typeAllowed({ name: 'd', type: 'dir' }),
      svgAsText: a({ name: 'a.svg', mime: 'text/plain' }),
      heicOctet: a({ name: 'a.heic', mime: 'application/octet-stream' }),
      docx: a({ name: 'a.docx', mime: 'application/octet-stream' }),
      exeOctet: a({ name: 'a.exe', mime: 'application/octet-stream' }),
    };
  });
  expect(res).toEqual({ txt: false, png: true, pdfBare: true, mimeOnly: true, dir: true, svgAsText: true, heicOctet: true, docx: true, exeOctet: false });
  await app(frame).evaluate((el: any) => {
    (window as any).Alpine.$data(el).selectFile({ name: 'a.txt', key: 'a.txt', type: 'file', url: '/x/a.txt' });
  });
  await page.waitForTimeout(500);
  expect((await sels(page)).length).toBe(0);
});

test('allowedTypes is picker-only: browser mode neither refuses selection nor restricts uploads', async ({ page }) => {
  const frame = await openPicker(page, { allowedTypes: ['image/*'], mode: 'browser' }, '/__pw_types_browser');
  await expect(frame.locator('input[type=file]').first()).not.toHaveAttribute('accept', /.+/);
  const name = `free-${Date.now()}.txt`;
  await uploadFile(frame, txtFile(name));
  await expect(cardByName(frame, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(frame, name).dblclick();
  await expect.poll(async () => (await sels(page)).length, { timeout: 10_000 }).toBe(1);
  expect((await sels(page))[0].name).toBe(name);
});

test('multi-select with a non-matching item selects the matching ones and names the skipped file', async ({ page }) => {
  const frame = await openPicker(page, { allowedTypes: ['image/*'], multiple: true }, '/__pw_types_multi');
  await app(frame).evaluate((el: any) => {
    const d = (window as any).Alpine.$data(el);
    d.selected = [
      { name: 'good.png', key: 'good.png', type: 'file', url: '/storage/uploads/good.png' },
      { name: 'skipme.zip', key: 'skipme.zip', type: 'file', url: '/storage/uploads/skipme.zip' },
    ];
    d.selectMultiple();
  });
  await expect.poll(async () => (await sels(page)).length).toBe(1);
  const payload = (await sels(page))[0];
  expect(payload.map((p: any) => p.name)).toEqual(['good.png']);
  await expect(frame.locator('.ff-toast')).toContainText('skipme.zip');
});

test('allowedTypes (picker): search results and URL import respect the filter', async ({ page }) => {
  const frame = await openPicker(page, {}, '/__pw_types_search');
  const stamp = Date.now();
  const png = `sx${stamp}.png`;
  const txt = `sx${stamp}.txt`;
  await uploadFile(frame, pngFile(png));
  await expect(cardByName(frame, png)).toBeVisible({ timeout: 15_000 });
  await uploadFile(frame, txtFile(txt));
  await expect(cardByName(frame, txt)).toBeVisible({ timeout: 15_000 });

  // Restrict at runtime (same state FM_CONFIG would set), then search.
  await app(frame).evaluate((el: any) => {
    const d = (window as any).Alpine.$data(el);
    d.config = Object.assign({}, d.config, { allowedTypes: ['image/*'] });
  });
  const searched = page.waitForResponse((r) => r.url().includes('/api/fm/search?'), { timeout: 15_000 }).catch(() => null);
  await frame.locator('input[x-model="searchQuery"]').first().fill(`sx${stamp}`);
  await searched;
  await expect(frame.locator(`.fname:text-is("${png}")`)).toBeVisible({ timeout: 10_000 });
  await expect(frame.locator(`.fname:text-is("${txt}")`)).toHaveCount(0);

  // URL import: a URL whose file extension is not allowed is refused before any request.
  const posted: string[] = [];
  page.on('request', (r) => { if (r.url().includes('/api/fm/import-url')) posted.push(r.url()); });
  const err = await app(frame).evaluate(async (el: any) => {
    const d = (window as any).Alpine.$data(el);
    d.urlImportUrl = 'https://example.com/files/report.zip';
    await d.importUrl();
    return d.urlImportError;
  });
  expect(err).toContain('report.zip');
  expect(posted).toHaveLength(0);
});
