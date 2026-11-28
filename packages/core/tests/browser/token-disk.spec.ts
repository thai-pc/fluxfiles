import { test, expect, type Page } from '@playwright/test';
import { mintTokenWithClaims } from './helpers';

// The UI picks its disk from what the host asked for AND what the token grants.
// Regression: `?token=` alone (README quick start) and the SDK/React/Vue defaults
// (disk 'local', disks ['local']) opened 'local' even when the token only granted
// e.g. ['s3'], so the first listing failed with "Access denied to this storage disk".
// The list API is stubbed: only the disk the UI asks for matters here.

function recordListDisks(page: Page): string[] {
  const disks: string[] = [];
  page.route('**/api/fm/list**', (route) => {
    disks.push(new URL(route.request().url()).searchParams.get('disk') || '');
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [], error: null }) });
  });
  return disks;
}

async function currentDisk(page: Page, frame = false): Promise<string> {
  const scope = frame ? page.frame({ url: /\/public\/index\.html/ })! : page.mainFrame();
  return scope.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).currentDisk);
}

test('?token= alone opens the token\'s own disk, not local', async ({ page }) => {
  const disks = recordListDisks(page);
  await page.goto(`/public/index.html?token=${mintTokenWithClaims({ disks: ['s3'] })}`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect.poll(() => disks.length).toBeGreaterThan(0);
  expect(disks[0]).toBe('s3');
  expect(await currentDisk(page)).toBe('s3');
});

test('a requested disk the token does not grant falls back to the token\'s disk', async ({ page }) => {
  const disks = recordListDisks(page);
  await page.goto(`/public/index.html?token=${mintTokenWithClaims({ disks: ['s3'] })}&disk=local&disks=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect.poll(() => disks.length).toBeGreaterThan(0);
  expect(new Set(disks)).toEqual(new Set(['s3']));
});

test('a requested disk the token grants is kept', async ({ page }) => {
  const disks = recordListDisks(page);
  await page.goto(`/public/index.html?token=${mintTokenWithClaims({ disks: ['local', 's3'] })}&disk=s3`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect.poll(() => disks.length).toBeGreaterThan(0);
  expect(disks[0]).toBe('s3');
});

test('FM_CONFIG with the adapters\' default disk local opens the token\'s disk', async ({ page }) => {
  const disks = recordListDisks(page);
  const token = mintTokenWithClaims({ disks: ['s3'] });
  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:900px;height:600px;border:0"></iframe>
    <script>
      var done = false;
      window.addEventListener('message', function (e) {
        var m = e.data; if (!m || m.source !== 'fluxfiles' || m.type !== 'FM_READY' || done) return;
        done = true;
        document.getElementById('fm').contentWindow.postMessage({ source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'h',
          payload: { token: ${JSON.stringify(token)}, disk: 'local', disks: ['local'], endpoint: location.origin } }, '*');
      });
    </script>
  </body></html>`;
  await page.route('**/__disk_host', (r) => r.fulfill({ contentType: 'text/html', body: host }));
  await page.goto('/__disk_host');
  await expect(page.frameLocator('#fm').locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect.poll(() => disks.length).toBeGreaterThan(0);
  expect(new Set(disks)).toEqual(new Set(['s3']));
  expect(await currentDisk(page, true)).toBe('s3');
});
