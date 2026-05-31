import { test, expect } from '@playwright/test';
import {
  mintToken,
  pngFile,
  imageFile,
  cardByName,
  openManager,
  createFolder,
  enterFolder,
  uploadFile,
} from './helpers';

// Each test works inside its own freshly-created folder so it is isolated from
// any files left behind by previous runs (uploads persist in storage/uploads).

test('upload a file through the UI → it appears in the grid', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-upload-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const fname = `hello-${Date.now()}.png`;
  await uploadFile(page, pngFile(fname));

  await expect(cardByName(page, fname)).toBeVisible({ timeout: 15_000 });
});

test('create a folder and navigate in and back out via the breadcrumb', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-nav-${Date.now()}`;
  await createFolder(page, page, folder);

  // Into the folder (empty) …
  await enterFolder(page, folder);
  // … then back to root via the home breadcrumb (idx 0).
  await page.locator('.ff-breadcrumb button').first().click();
  await expect(cardByName(page, folder)).toBeVisible({ timeout: 10_000 });
});

test('search narrows the grid to the matching file', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-search-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const stamp = Date.now();
  const alpha = `alpha-${stamp}.png`;
  const beta = `beta-${stamp}.png`;
  await uploadFile(page, pngFile(alpha));
  await expect(cardByName(page, alpha)).toBeVisible({ timeout: 15_000 });
  await uploadFile(page, pngFile(beta));
  await expect(cardByName(page, beta)).toBeVisible({ timeout: 15_000 });

  // Server search matches the file key (includes the filename); query the unique stem.
  await page.locator('.ff-search-box input').first().fill(`alpha-${stamp}`);
  await expect(cardByName(page, alpha)).toBeVisible({ timeout: 10_000 });
  await expect(cardByName(page, beta)).toHaveCount(0);
});

test('dark-mode toggle adds and removes the `dark` class on <html>', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const html = page.locator('html');
  const toggle = page.locator('.ff-theme-toggle-sm').first();

  await toggle.click();
  await expect(html).toHaveClass(/\bdark\b/);

  await toggle.click();
  await expect(html).not.toHaveClass(/\bdark\b/);
});

test('delete a file through the UI removes it from the grid', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-del-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const fname = `doomed-${Date.now()}.png`;
  await uploadFile(page, pngFile(fname));
  const card = cardByName(page, fname);
  await expect(card).toBeVisible({ timeout: 15_000 });

  // Select (single click) then delete via the contextual toolbar button + confirm.
  await card.click();
  await page.locator('.ff-toolbar-context .tb-btn.danger').click();
  await page.locator('.ff-confirm-box .ff-btn-danger').click();

  await expect(card).toHaveCount(0, { timeout: 15_000 });
  await expect(page.locator('.ff-empty:has(.ff-empty-cta)')).toBeVisible();
});

test('inline crop → "Save as Copy" produces a cropped file in the grid', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-crop-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const stamp = Date.now();
  const src = `photo-${stamp}.png`;
  await uploadFile(page, imageFile(src, 120, 80));
  const card = cardByName(page, src);
  await expect(card).toBeVisible({ timeout: 15_000 });

  // Open the detail panel (double-click) then switch to the Crop tab.
  await card.dblclick();
  await page.getByRole('button', { name: 'Crop', exact: true }).click();

  // initCrop seeds a centred 80% selection once the image loads → Save enables.
  const saveCopy = page.getByRole('button', { name: 'Save as Copy', exact: true });
  await expect(saveCopy).toBeEnabled({ timeout: 10_000 });
  await saveCopy.click();

  // saveCrop('copy') writes "{base}_cropped.{ext}" into the current folder.
  await expect(cardByName(page, `photo-${stamp}_cropped.png`)).toBeVisible({ timeout: 15_000 });
});

test('picker mode: double-clicking a file emits FM_SELECT to the host page', async ({ page }) => {
  const token = mintToken();

  // A minimal host page that embeds the iframe, completes the FM_READY → FM_CONFIG
  // handshake, and records the FM_SELECT payload the UI posts back.
  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:1000px;height:680px;border:0"></iframe>
    <script>
      window.__sel = null;
      window.addEventListener('message', function (e) {
        var m = e.data;
        if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY') {
          document.getElementById('fm').contentWindow.postMessage({
            source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'host-1',
            payload: { token: ${JSON.stringify(token)}, disk: 'local', endpoint: location.origin, multiple: false }
          }, '*');
        }
        if (m.type === 'FM_SELECT') { window.__sel = m.payload; }
      });
    </script>
  </body></html>`;

  await page.route('**/__pw_host', (route) =>
    route.fulfill({ contentType: 'text/html', body: host })
  );
  await page.goto('/__pw_host');

  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-pick-${Date.now()}`;
  await createFolder(frame, page, folder);
  await enterFolder(frame, folder);

  const fname = `picked-${Date.now()}.png`;
  await uploadFile(frame, pngFile(fname));
  const card = cardByName(frame, fname);
  await expect(card).toBeVisible({ timeout: 15_000 });

  await card.dblclick();

  await expect.poll(() => page.evaluate(() => (window as any).__sel), { timeout: 10_000 }).toBeTruthy();
  const sel = await page.evaluate(() => (window as any).__sel);
  expect(sel.name).toBe(fname);
  expect(sel.disk).toBe('local');
  expect(String(sel.key)).toContain(fname);
});

test('picker multiple: selecting several files emits an FM_SELECT array', async ({ page }) => {
  const token = mintToken();

  // Same host handshake, but config.multiple = true so the UI returns an array.
  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:1000px;height:680px;border:0"></iframe>
    <script>
      window.__sel = null;
      window.addEventListener('message', function (e) {
        var m = e.data;
        if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY') {
          document.getElementById('fm').contentWindow.postMessage({
            source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'host-multi',
            payload: { token: ${JSON.stringify(token)}, disk: 'local', endpoint: location.origin, multiple: true }
          }, '*');
        }
        if (m.type === 'FM_SELECT') { window.__sel = m.payload; }
      });
    </script>
  </body></html>`;

  await page.route('**/__pw_host_multi', (route) =>
    route.fulfill({ contentType: 'text/html', body: host })
  );
  await page.goto('/__pw_host_multi');

  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-multi-${Date.now()}`;
  await createFolder(frame, page, folder);
  await enterFolder(frame, folder);

  const stamp = Date.now();
  const a = `multi-a-${stamp}.png`;
  const b = `multi-b-${stamp}.png`;
  await uploadFile(frame, pngFile(a));
  await expect(cardByName(frame, a)).toBeVisible({ timeout: 15_000 });
  await uploadFile(frame, pngFile(b));
  await expect(cardByName(frame, b)).toBeVisible({ timeout: 15_000 });

  // Ctrl/Cmd-click accumulates the selection (toggleSelect with ctrlKey/metaKey).
  await cardByName(frame, a).click({ modifiers: ['ControlOrMeta'] });
  await cardByName(frame, b).click({ modifiers: ['ControlOrMeta'] });

  // The "Select N items" toolbar button (only shown when multiple && selected > 0)
  // fires selectMultiple(), which posts the whole selection as an array.
  await frame.getByRole('button', { name: /Select \d+ items?/ }).click();

  await expect.poll(() => page.evaluate(() => (window as any).__sel), { timeout: 10_000 }).toBeTruthy();
  const sel = await page.evaluate(() => (window as any).__sel);
  expect(Array.isArray(sel)).toBe(true);
  expect(sel.length).toBe(2);
  expect(sel.map((s: any) => s.name).sort()).toEqual([a, b].sort());
});

test('bulk delete: select multiple files and delete them all at once', async ({ page }) => {
  const token = mintToken();
  // multiple=1 enables multi-select; Ctrl/Cmd-click accumulates the selection.
  await page.goto(`/public/index.html?token=${token}&disk=local&multiple=1`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-bulkdel-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const stamp = Date.now();
  const a = `del-a-${stamp}.png`;
  const b = `del-b-${stamp}.png`;
  await uploadFile(page, pngFile(a));
  await expect(cardByName(page, a)).toBeVisible({ timeout: 15_000 });
  await uploadFile(page, pngFile(b));
  await expect(cardByName(page, b)).toBeVisible({ timeout: 15_000 });

  await cardByName(page, a).click({ modifiers: ['ControlOrMeta'] });
  await cardByName(page, b).click({ modifiers: ['ControlOrMeta'] });

  // Contextual bulk delete button (shows count) → confirm dialog → deleteSelected().
  await page.locator('.ff-toolbar-context .tb-btn.danger').click();
  await page.locator('.ff-confirm-box .ff-btn-danger').click();

  await expect(cardByName(page, a)).toHaveCount(0, { timeout: 15_000 });
  await expect(cardByName(page, b)).toHaveCount(0);
  await expect(page.locator('.ff-empty:has(.ff-empty-cta)')).toBeVisible();
});

test('bulk move: move multiple files into a subfolder at once', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local&multiple=1`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const base = `pw-bulkmv-${Date.now()}`;
  await createFolder(page, page, base);
  await enterFolder(page, base);
  await createFolder(page, page, 'dest'); // destination subfolder

  const stamp = Date.now();
  const a = `mv-a-${stamp}.png`;
  const b = `mv-b-${stamp}.png`;
  await uploadFile(page, pngFile(a));
  await expect(cardByName(page, a)).toBeVisible({ timeout: 15_000 });
  await uploadFile(page, pngFile(b));
  await expect(cardByName(page, b)).toBeVisible({ timeout: 15_000 });

  await cardByName(page, a).click({ modifiers: ['ControlOrMeta'] });
  await cardByName(page, b).click({ modifiers: ['ControlOrMeta'] });

  // Toolbar "Move" opens the bulk-move modal; pick the `dest` quick-pick + confirm.
  await page.getByRole('button', { name: 'Move', exact: true }).click();
  const modal = page.locator('.ff-confirm-overlay:has(input[x-model="bulkMoveTarget"])');
  await modal.getByRole('button', { name: 'dest', exact: true }).click();
  await modal.getByRole('button', { name: 'Move', exact: true }).click();

  // Files leave the source view…
  await expect(cardByName(page, a)).toHaveCount(0, { timeout: 15_000 });
  await expect(cardByName(page, b)).toHaveCount(0);
  // …and land inside `dest`.
  await cardByName(page, 'dest').dblclick();
  await expect(cardByName(page, a)).toBeVisible({ timeout: 15_000 });
  await expect(cardByName(page, b)).toBeVisible();
});
