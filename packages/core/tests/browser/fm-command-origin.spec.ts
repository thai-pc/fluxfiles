import { test, expect, type Page } from '@playwright/test';
import { mintToken } from './helpers';

// FM_COMMAND acts with the UI's current token, so it must only be accepted once a
// parent origin is established (the FM_CONFIG lock, or __FM_BOOT__.parentOrigin).
// A server-injected __FM_BOOT__ without parentOrigin never locks one — before the
// fix, ANY page framing the UI could drive it with FM_COMMAND (A-N9).

const MARK = 'pw-cmd-origin';

/** Count /api/fm/list requests for the marker path the FM_COMMAND navigates to. */
async function countMarkedLists(page: Page): Promise<{ n: number }> {
  const seen = { n: 0 };
  await page.route('**/api/fm/**', (route) => {
    const u = new URL(route.request().url());
    if (u.pathname.endsWith('/api/fm/list') && (u.searchParams.get('path') || '').startsWith(MARK)) seen.n++;
    return route.continue();
  });
  return seen;
}

/** Host page: on FM_READY optionally completes the FM_CONFIG handshake, then sends FM_COMMAND navigate. */
async function openHost(page: Page, opts: { token: string; handshake: boolean }) {
  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:900px;height:600px;border:0"></iframe>
    <script>
      var done = false;
      window.addEventListener('message', function (e) {
        var m = e.data; if (!m || m.source !== 'fluxfiles' || m.type !== 'FM_READY' || done) return;
        done = true;
        var w = document.getElementById('fm').contentWindow;
        ${opts.handshake ? `w.postMessage({ source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'h',
          payload: { token: ${JSON.stringify(opts.token)}, disk: 'local', endpoint: location.origin } }, '*');` : ''}
        setTimeout(function () {
          w.postMessage({ source: 'fluxfiles', type: 'FM_COMMAND', v: 1, id: 'c',
            payload: { action: 'navigate', path: '${MARK}' } }, '*');
        }, 500);
      });
    </script>
  </body></html>`;
  await page.route('**/__cmd_host', (r) => r.fulfill({ contentType: 'text/html', body: host }));
  await page.goto('/__cmd_host');
  await expect(page.frameLocator('#fm').locator('.ff-app')).toBeVisible({ timeout: 15_000 });
}

/** Inject a server-style __FM_BOOT__ into the framed UI only (not the host page). */
async function injectBoot(page: Page, boot: Record<string, unknown>) {
  await page.addInitScript((b) => {
    if (window !== window.top && location.pathname.endsWith('/public/index.html')) {
      (window as unknown as { __FM_BOOT__: unknown }).__FM_BOOT__ = { ...b, endpoint: location.origin };
    }
  }, boot);
}

test('__FM_BOOT__ without parentOrigin ignores FM_COMMAND from the framing page', async ({ page }) => {
  const seen = await countMarkedLists(page);
  await injectBoot(page, { token: mintToken(['read']), disk: 'local' });
  await openHost(page, { token: '', handshake: false });
  await page.waitForTimeout(1500);
  expect(seen.n).toBe(0);
});

test('__FM_BOOT__ with a matching parentOrigin accepts FM_COMMAND', async ({ page, baseURL }) => {
  const seen = await countMarkedLists(page);
  await injectBoot(page, { token: mintToken(['read']), disk: 'local', parentOrigin: new URL(baseURL!).origin });
  await openHost(page, { token: '', handshake: false });
  await expect.poll(() => seen.n, { timeout: 5_000 }).toBeGreaterThan(0);
});

test('normal FM_CONFIG handshake still accepts FM_COMMAND', async ({ page }) => {
  const seen = await countMarkedLists(page);
  await openHost(page, { token: mintToken(['read']), handshake: true });
  await expect.poll(() => seen.n, { timeout: 5_000 }).toBeGreaterThan(0);
});
