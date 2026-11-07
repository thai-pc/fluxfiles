import { test, expect, type Page } from '@playwright/test';
import { mintToken, pngFile, cardByName, createFolder, enterFolder, uploadFile } from './helpers';

// Host page that embeds the UI iframe, answers the FM_READY -> FM_CONFIG handshake
// with the given extra config, and records every FM_SELECT payload.
async function openPicker(page: Page, extra: Record<string, unknown>, route: string) {
  const token = mintToken();
  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:1000px;height:680px;border:0"></iframe>
    <script>
      window.__sels = [];
      window.addEventListener('message', function (e) {
        var m = e.data;
        if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY') {
          document.getElementById('fm').contentWindow.postMessage({
            source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'host-1',
            payload: Object.assign({ token: ${JSON.stringify(token)}, disk: 'local',
              endpoint: location.origin, mode: 'picker' }, ${JSON.stringify(extra)})
          }, '*');
        }
        if (m.type === 'FM_SELECT') { window.__sels.push(m.payload); }
      });
    </script></body></html>`;
  await page.route('**' + route, (r) => r.fulfill({ contentType: 'text/html', body: host }));
  await page.goto(route);
  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  const folder = `pw-pt-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  await createFolder(frame, page, folder);
  await enterFolder(frame, folder);
  return frame;
}
const sels = (page: Page) => page.evaluate(() => (window as any).__sels as any[]);

test('FM_SELECT payload carries absolute urls (url, variants, img_base, img_srcset)', async ({ page }) => {
  const frame = await openPicker(page, {}, '/__pw_abs');
  const name = `abs-${Date.now()}.png`;
  await uploadFile(frame, pngFile(name));
  await expect(cardByName(frame, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(frame, name).dblclick();
  await expect.poll(async () => (await sels(page)).length, { timeout: 10_000 }).toBeGreaterThan(0);
  const sel = (await sels(page))[0];
  const origin = new URL(page.url()).origin;
  expect(sel.url).toMatch(/^https?:\/\//);
  expect(sel.url.startsWith(origin + '/')).toBe(true);
  for (const v of Object.values<any>(sel.variants || {})) expect(v.url).toMatch(/^https?:\/\//);
  if (sel.img_base) expect(sel.img_base).toMatch(/^https?:\/\//);
  if (sel.img_srcset) {
    for (const cand of String(sel.img_srcset).split(',')) {
      expect(cand.trim()).toMatch(/^https?:\/\//);
    }
  }
});

test('allowedTypes: listing is filtered, selection of a non-matching file is refused, upload input has accept', async ({ page }) => {
  const frame = await openPicker(page, { allowedTypes: ['image/*', '.pdf'] }, '/__pw_types');
  // accept attribute reflects the restriction (extensions + MIME patterns).
  await expect(frame.locator('input[type=file]').first()).toHaveAttribute('accept', 'image/*,.pdf');

  const img = `ok-${Date.now()}.png`;
  await uploadFile(frame, pngFile(img));
  await expect(cardByName(frame, img)).toBeVisible({ timeout: 15_000 });

  // A non-matching file dropped past the input's accept filter is refused client side.
  const txt = `no-${Date.now()}.txt`;
  await uploadFile(frame, { name: txt, mimeType: 'text/plain', buffer: Buffer.from('hello ' + Math.random()) });
  await expect(frame.locator('.ff-toast, .toast').first()).toBeVisible({ timeout: 10_000 });
  await expect(cardByName(frame, txt)).toHaveCount(0);

  // The matching file is selectable.
  await cardByName(frame, img).dblclick();
  await expect.poll(async () => (await sels(page)).length, { timeout: 10_000 }).toBe(1);
});

test('allowedTypes: matcher semantics and selectFile refusal emit nothing', async ({ page }) => {
  const frame = await openPicker(page, { allowedTypes: ['image/*', 'pdf'] }, '/__pw_types_unit');
  const res = await frame.locator('.ff-app').evaluate((el: any) => {
    const d = (window as any).Alpine.$data(el);
    return {
      txt: d._typeAllowed({ name: 'a.txt', type: 'file' }),
      png: d._typeAllowed({ name: 'a.PNG', type: 'file' }),
      pdfBare: d._typeAllowed({ name: 'a.pdf', type: 'file' }),
      mimeOnly: d._typeAllowed({ name: 'blob', mime: 'image/webp', type: 'file' }),
      dir: d._typeAllowed({ name: 'd', type: 'dir' }),
    };
  });
  expect(res).toEqual({ txt: false, png: true, pdfBare: true, mimeOnly: true, dir: true });
  await frame.locator('.ff-app').evaluate((el: any) => {
    (window as any).Alpine.$data(el).selectFile({ name: 'a.txt', key: 'a.txt', type: 'file', url: '/x/a.txt' });
  });
  await page.waitForTimeout(500);
  expect((await sels(page)).length).toBe(0);
});
