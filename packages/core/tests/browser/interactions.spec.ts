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

test('iframe init fires list/quota/lang exactly once per config (no duplicate requests)', async ({ page }) => {
  const token = mintToken();

  // Host that completes the handshake and sends the SAME FM_CONFIG three times —
  // mimicking a chatty wrapper (React/Vue re-renders / double-send). The UI must
  // not re-fire list+quota+lang for duplicate configs (regression: Alpine was
  // double-initialising AND there was no idempotency guard → doubled requests).
  const counts: Record<string, number> = { list: 0, quota: 0, lang: 0 };
  await page.route('**/api/fm/**', (route) => {
    const m = route.request().url().match(/\/api\/fm\/(list|quota|lang)/);
    if (m) counts[m[1]]++;
    return route.continue();
  });

  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:900px;height:600px;border:0"></iframe>
    <script>
      var done = false;
      window.addEventListener('message', function (e) {
        var m = e.data; if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY' && !done) {
          done = true;
          var w = document.getElementById('fm').contentWindow;
          var cfg = { source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'h',
            payload: { token: ${JSON.stringify(token)}, disk: 'local', endpoint: location.origin, locale: 'vi' } };
          w.postMessage(cfg, '*');
          w.postMessage(cfg, '*');
          setTimeout(function () { w.postMessage(cfg, '*'); }, 200);
        }
      });
    </script>
  </body></html>`;
  await page.route('**/__cfg_host', (r) => r.fulfill({ contentType: 'text/html', body: host }));
  await page.goto('/__cfg_host');

  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(1500); // let any stray duplicate requests land

  expect(counts.list).toBe(1);
  expect(counts.quota).toBe(1);
  expect(counts.lang).toBe(1);
});

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

test('maxUploadMb blocks an oversized file client-side (toast, no upload)', async ({ page }) => {
  const token = mintToken();
  // Standalone URL param sets config.maxUploadMb = 1 (MB).
  await page.goto(`/public/index.html?token=${token}&disk=local&maxUploadMb=1`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-maxmb-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  // A ~2 MB "file" — exceeds the 1 MB client limit.
  const big = { name: `big-${Date.now()}.png`, mimeType: 'image/png', buffer: Buffer.alloc(2 * 1024 * 1024, 7) };

  let uploadCalled = false;
  await page.route('**/api/fm/upload', (r) => { uploadCalled = true; return r.continue(); });

  await page.locator('input[type=file]').first().setInputFiles(big);

  await expect(page.locator('.ff-toast')).toBeVisible({ timeout: 5_000 });
  await page.waitForTimeout(800);
  expect(uploadCalled).toBe(false);               // never hit the API
  await expect(cardByName(page, big.name)).toHaveCount(0);
});

test('maxFiles caps an oversized drop batch client-side', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local&maxFiles=2`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-maxf-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const stamp = Date.now();
  const fs = [`x1-${stamp}.png`, `x2-${stamp}.png`, `x3-${stamp}.png`].map((n) => pngFile(n));
  await page.locator('input[type=file]').first().setInputFiles(fs);

  await expect(page.locator('.ff-toast')).toBeVisible({ timeout: 5_000 });
  // Batch sliced to maxFiles=2 → exactly 2 cards, never 3.
  await expect(page.locator('.file-card')).toHaveCount(2, { timeout: 15_000 });
});

test('dropping a file OUTSIDE the dropzone uploads it (no browser navigation)', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-drop-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const urlBefore = page.url();
  const fname = `dropped-${Date.now()}.png`;

  // Simulate a real drag-drop of a file onto <body> (well outside .ff-dropzone).
  // Without the global drop guard the browser would navigate to the raw file.
  await page.evaluate((name) => {
    const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
    const bin = atob(b64);
    const arr = new Uint8Array(bin.length + 16);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    for (let i = 0; i < 16; i++) arr[bin.length + i] = Math.floor(Math.random() * 256); // unique hash
    const dt = new DataTransfer();
    dt.items.add(new File([arr], name, { type: 'image/png' }));
    document.body.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
  }, fname);

  await expect(cardByName(page, fname)).toBeVisible({ timeout: 15_000 });
  expect(page.url()).toBe(urlBefore); // app did not navigate away
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

test('explicit ?theme=dark overrides a saved light preference (host override wins)', async ({ page }) => {
  const token = mintToken();

  // User had previously chosen light and it was persisted.
  await page.goto('/public/index.html');
  await page.evaluate(() => localStorage.setItem('fluxfiles_theme', 'light'));

  // Host embeds with an explicit dark theme — the override must win over storage.
  await page.goto(`/public/index.html?token=${token}&disk=local&theme=dark`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('html')).toHaveClass(/\bdark\b/);

  // The override is not persisted, so the user's saved 'light' choice survives.
  const stored = await page.evaluate(() => localStorage.getItem('fluxfiles_theme'));
  expect(stored).toBe('light');
});

test('saved preference applies when host gives no explicit theme', async ({ page }) => {
  const token = mintToken();

  await page.goto('/public/index.html');
  await page.evaluate(() => localStorage.setItem('fluxfiles_theme', 'dark'));

  await page.goto(`/public/index.html?token=${token}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('html')).toHaveClass(/\bdark\b/);
});

test('statically-served page (no locale injection) shows the boot spinner then reveals translated UI — never raw keys', async ({ page }) => {
  const token = mintToken();

  // Simulate a page served WITHOUT the server-side __FM_LOCALE__ injection
  // (e.g. nginx/CDN serving index.html as a static file): swallow the injected
  // assignment so the app boots with empty messages.
  await page.addInitScript(() => {
    Object.defineProperty(window, '__FM_LOCALE__', {
      configurable: true,
      get() { return { locale: 'en', dir: 'ltr', messages: {} }; },
      set() { /* swallow server injection */ },
    });
  });

  // Slow the lang fetch so the boot state is observable.
  await page.route('**/api/fm/lang*', async (route) => {
    await new Promise((r) => setTimeout(r, 600));
    await route.continue();
  });

  await page.goto(`/public/index.html?token=${token}&disk=local`);

  // While messages load the opaque boot overlay covers the UI (raw keys behind it
  // are never visible to the user).
  await expect(page.locator('.ff-i18n-boot')).toBeVisible();

  // Once messages arrive the overlay disappears and no raw i18n key is rendered.
  await expect(page.locator('.ff-i18n-boot')).toBeHidden({ timeout: 5_000 });
  await expect(page.locator('.ff-app')).toBeVisible();
  await expect(page.getByText('toolbar.upload', { exact: true })).toHaveCount(0);
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

test('upload progress UI shows spinner, current filename, and N/total', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local&multiple=1`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-prog-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  // Hold each upload request ~900ms so the progress bar stays observable.
  await page.route('**/api/fm/upload', async (route) => {
    await new Promise((r) => setTimeout(r, 900));
    await route.continue();
  });

  const stamp = Date.now();
  const a = `prog-a-${stamp}.png`;
  const b = `prog-b-${stamp}.png`;
  await page.locator('input[type=file]').first().setInputFiles([pngFile(a), pngFile(b)]);

  // While in flight: animated spinner, the current file name, and the (n/2) count.
  await expect(page.locator('.ff-upload-spinner')).toBeVisible({ timeout: 5_000 });
  await expect(page.locator('.ff-upload-name')).toBeVisible();
  await expect(page.locator('.ff-upload-name')).not.toHaveText('');
  await expect(page.locator('.ff-upload-count')).toHaveText(/\([12]\/2\)/);

  await page.unroute('**/api/fm/upload');
  // Both files finish and land in the grid.
  await expect(cardByName(page, a)).toBeVisible({ timeout: 15_000 });
  await expect(cardByName(page, b)).toBeVisible({ timeout: 15_000 });
});

test('bulk download: triggers a download for each selected file', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local&multiple=1`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-bulkdl-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const stamp = Date.now();
  const a = `dl-a-${stamp}.png`;
  const b = `dl-b-${stamp}.png`;
  await uploadFile(page, pngFile(a));
  await expect(cardByName(page, a)).toBeVisible({ timeout: 15_000 });
  await uploadFile(page, pngFile(b));
  await expect(cardByName(page, b)).toBeVisible({ timeout: 15_000 });

  await cardByName(page, a).click({ modifiers: ['ControlOrMeta'] });
  await cardByName(page, b).click({ modifiers: ['ControlOrMeta'] });

  // Collect the per-file downloads bulkDownload() fires (one <a download> per file).
  const downloads: string[] = [];
  page.on('download', (d) => downloads.push(d.suggestedFilename()));

  await page.getByRole('button', { name: 'Download', exact: true }).click();

  await expect.poll(() => downloads.length, { timeout: 10_000 }).toBeGreaterThanOrEqual(2);
  expect(downloads).toContain(a);
  expect(downloads).toContain(b);
});
