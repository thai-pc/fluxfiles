import { test, expect } from '@playwright/test';
import {
  mintToken,
  mintTokenWithClaims,
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

test('grid shows a created date on files and folders', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-date-${Date.now()}`;
  await createFolder(page, page, folder);
  // The folder card carries a created date (digit-containing, locale-agnostic).
  await expect(cardByName(page, folder).locator('.fdate')).toHaveText(/\d/, { timeout: 10_000 });

  await enterFolder(page, folder);
  const name = `dated-${Date.now()}.png`;
  await uploadFile(page, pngFile(name));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await expect(cardByName(page, name).locator('.fdate')).toHaveText(/\d/);
});

test('search results also render the created date', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const stamp = Date.now();
  const folder = `pw-sdate-${stamp}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);
  const file = `report-${stamp}.png`;
  await uploadFile(page, pngFile(file));
  await expect(cardByName(page, file)).toBeVisible({ timeout: 15_000 });

  await page.locator('.ff-search-box input').first().fill(`report-${stamp}`);
  await expect(cardByName(page, file)).toBeVisible({ timeout: 10_000 });
  await expect(cardByName(page, file).locator('.fdate')).toHaveText(/\d/);
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

test('rename locks the file extension: only the base name is editable', async ({ page }) => {
  const token = mintToken();
  await openManager(page, token);

  const folder = `pw-rename-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  const stamp = Date.now();
  const fname = `lock-${stamp}.png`;
  await uploadFile(page, pngFile(fname));
  const card = cardByName(page, fname);
  await expect(card).toBeVisible({ timeout: 15_000 });

  // Select → open rename via the contextual toolbar button.
  await card.click();
  await page.locator('.ff-toolbar-context .tb-btn', { hasText: 'Rename' }).click();

  // The input holds only the base name; the extension is shown locked beside it.
  const input = page.locator('.ff-rename-base');
  await expect(input).toBeVisible();
  await expect(input).toHaveValue(`lock-${stamp}`);
  await expect(page.locator('.ff-rename-ext')).toHaveText('.png');

  // Editing the base re-attaches the .png automatically.
  await input.fill(`renamed-${stamp}`);
  await input.press('Enter');

  await expect(cardByName(page, `renamed-${stamp}.png`)).toBeVisible({ timeout: 15_000 });
  await expect(cardByName(page, fname)).toHaveCount(0);
});

test('activity log: only an audit-perm token shows the panel, and it lists entries', async ({ page }) => {
  // Without the audit perm the Activity button is hidden.
  await openManager(page, mintToken(['read', 'write', 'delete']));
  await expect(page.getByRole('button', { name: 'Activity' })).toHaveCount(0);

  // With it, the panel opens and lists the activity we just generated.
  await openManager(page, mintToken(['read', 'write', 'delete', 'audit']));
  const folder = `pw-audit-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);
  const fname = `act-${Date.now()}.png`;
  await uploadFile(page, pngFile(fname));

  await page.getByRole('button', { name: 'Activity' }).click();
  const modal = page.locator('.ff-activity-modal');
  await expect(modal).toBeVisible();
  await expect(modal.locator('.ff-activity-table tbody tr').first()).toBeVisible({ timeout: 10_000 });
  // The upload entry now carries the file key (not blank), so its name shows.
  await expect(modal.locator('.ff-activity-file', { hasText: fname })).toBeVisible();
});

test('bucket doctor: write token opens the panel and reports on the disk', async ({ page }) => {
  // A read-only token has no Diagnose button (needs write).
  await openManager(page, mintToken(['read']));
  await expect(page.getByRole('button', { name: 'Bucket health' })).toHaveCount(0);

  // With write, the panel opens and the local disk reports healthy.
  await openManager(page, mintToken(['read', 'write', 'delete']));
  await page.getByRole('button', { name: 'Bucket health' }).click();
  await expect(page.locator('.ff-doctor-checks')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('.ff-doctor-check').first()).toBeVisible();
  await expect(page.locator('.ff-doctor-summary')).toHaveText(/Healthy/);
});

test('trash: deleting a file moves it to Trash, and Restore brings it back', async ({ page }) => {
  await openManager(page, mintToken(['read', 'write', 'delete']));
  const folder = `pw-trash-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);
  const fname = `t-${Date.now()}.png`;
  await uploadFile(page, pngFile(fname));
  const card = cardByName(page, fname);
  await expect(card).toBeVisible({ timeout: 15_000 });

  // Single-file delete → soft delete to trash (file leaves the grid).
  await card.click();
  await page.locator('.ff-toolbar-context .tb-btn.danger').click();
  await page.locator('.ff-confirm-box .ff-btn-danger').click();
  await expect(card).toHaveCount(0, { timeout: 15_000 });

  // Trash panel lists it → Restore.
  await page.getByRole('button', { name: 'Trash', exact: true }).click();
  const modal = page.locator('.ff-trash-modal');
  await expect(modal).toBeVisible();
  const row = modal.locator('.ff-activity-table tbody tr', { hasText: fname });
  await expect(row).toBeVisible({ timeout: 10_000 });
  await row.getByRole('button', { name: 'Restore' }).click();

  // Close the panel — the file is back in the grid.
  await modal.locator('.ff-activity-close').click();
  await expect(cardByName(page, fname)).toBeVisible({ timeout: 15_000 });
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
  // The on-demand WebP base rides the select payload, so the host can build any
  // size via FluxFiles.imgUrl(file, {width, quality}).
  expect(String(sel.img_base)).toContain('/api/fm/img');
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

test('postMessage from a non-parent window (e.g. a sibling iframe) is ignored: only the real parent can configure the iframe', async ({ page }) => {
  const token = mintToken();

  // Regression test for a spoofing bug: the message listener used to trust
  // whichever origin sent the FIRST FM_CONFIG, with no check that the sender
  // was actually window.parent. A sibling iframe living in the same host page
  // (e.g. a third-party ad/widget script) can grab a reference to the fm
  // iframe via window.top.frames[...] and race the real host to send a
  // malicious FM_CONFIG pointing at an attacker endpoint/token. Same-origin
  // here is enough to prove the point: e.source differs from window.parent
  // for ANY sibling frame, cross-origin or not.
  const host = `<!doctype html><html><body>
    <iframe id="fm" name="fm" src="/public/index.html" style="width:900px;height:600px;border:0"></iframe>
    <iframe id="attacker" name="attacker" srcdoc="${[
      '<script>',
      "  var evil = { source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'evil',",
      "    payload: { token: 'attacker-token', disk: 'local', endpoint: 'https://attacker.example', path: '' } };",
      "  function fire() { try { window.top.frames['fm'].postMessage(evil, '*'); } catch (e) {} }",
      '  fire();', // as early as possible, before the real host even sees FM_READY
      '  setInterval(fire, 25);', // keep racing throughout the test
      '</script>',
    ].join('&#10;')}"></iframe>
    <script>
      window.__realConfigSent = false;
      window.addEventListener('message', function (e) {
        var m = e.data; if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY') {
          document.getElementById('fm').contentWindow.postMessage({
            source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'host-real',
            payload: { token: ${JSON.stringify(token)}, disk: 'local', endpoint: location.origin }
          }, '*');
          window.__realConfigSent = true;
        }
      });
    </script>
  </body></html>`;

  await page.route('**/__hijack_host', (route) => route.fulfill({ contentType: 'text/html', body: host }));
  // The attacker's endpoint is a different origin entirely; nothing should ever
  // reach it if the spoofed FM_CONFIG was correctly rejected.
  let attackerHit = false;
  await page.route('https://attacker.example/**', (route) => {
    attackerHit = true;
    return route.abort();
  });

  await page.goto('/__hijack_host');
  await expect.poll(() => page.evaluate(() => (window as any).__realConfigSent)).toBe(true);

  // The legit host's FM_CONFIG must win: the real UI boots against OUR origin
  // with the real token, despite the sibling frame spamming a spoofed config
  // both before and after it.
  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-hijack-${Date.now()}`;
  await createFolder(frame, page, folder);
  await enterFolder(frame, folder);

  const fname = `hijack-${Date.now()}.png`;
  await uploadFile(frame, pngFile(fname));
  await expect(cardByName(frame, fname)).toBeVisible({ timeout: 15_000 });

  // Give the attacker's 25ms interval plenty of chances to have fired.
  await page.waitForTimeout(500);
  expect(attackerHit).toBe(false);
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

test('sidebar tree auto-expands the path to the current folder', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  // Build A/B, then sit in B. The tree should auto-expand Root → A → B.
  const a = `pw-tree-a-${Date.now()}`;
  const b = `pw-tree-b-${Date.now()}`;
  await createFolder(page, page, a);
  await enterFolder(page, a);
  await createFolder(page, page, b);
  await enterFolder(page, b);

  const tree = page.locator('.ff-sidebar-section');
  // Parent A is shown as a tree node, and B is the active node (we're inside it).
  await expect(tree.locator('.tree-node .ti-label', { hasText: a })).toBeVisible({ timeout: 10_000 });
  const active = tree.locator('.tree-node.active');
  await expect(active).toHaveCount(1);
  await expect(active.locator('.ti-label')).toHaveText(b);
});

test('sidebar tree: chevron collapses/expands a branch without navigating', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const a = `pw-chev-a-${Date.now()}`;
  const child = `pw-chev-child-${Date.now()}`;
  await createFolder(page, page, a);
  await enterFolder(page, a);
  await createFolder(page, page, child);
  // Back to root so `a` is a collapsible branch in the tree.
  await page.locator('.ff-breadcrumb button', { hasText: 'Root' }).click();

  const aNode = page.locator('.ff-sidebar-section .tree-node', { hasText: a }).first();
  await expect(aNode).toBeVisible({ timeout: 10_000 });
  const childRow = page.locator('.ff-sidebar-section .tree-node .ti-label', { hasText: child });
  // `a` stays auto-expanded after we visited it, so its child is already shown.
  await expect(childRow).toBeVisible({ timeout: 10_000 });
  // Chevron collapses without navigating: child hidden, path stays at Root.
  await aNode.locator('.tree-toggle').click();
  await expect(childRow).toHaveCount(0);
  await expect(page.locator('.ff-breadcrumb .bc-active')).toHaveText(/Root/);
  // Chevron expands again: child reappears.
  await aNode.locator('.tree-toggle').click();
  await expect(childRow).toBeVisible({ timeout: 10_000 });
});

test('upload progress UI shows spinner, current filename, and N/total', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local&multiple=1`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const folder = `pw-prog-${Date.now()}`;
  await createFolder(page, page, folder);
  await enterFolder(page, folder);

  // Hold each upload request ~900ms so the progress bar stays observable.
  // Guard continue() + unroute by reference: a request still sleeping when we
  // unroute would otherwise throw "Route is already handled!" and flake.
  const holdUpload = async (route: import('@playwright/test').Route) => {
    await new Promise((r) => setTimeout(r, 900));
    try { await route.continue(); } catch { /* unrouted mid-flight */ }
  };
  await page.route('**/api/fm/upload', holdUpload);

  const stamp = Date.now();
  const a = `prog-a-${stamp}.png`;
  const b = `prog-b-${stamp}.png`;
  await page.locator('input[type=file]').first().setInputFiles([pngFile(a), pngFile(b)]);

  // While in flight: animated spinner, the current file name, and the (n/2) count.
  await expect(page.locator('.ff-upload-spinner')).toBeVisible({ timeout: 5_000 });
  await expect(page.locator('.ff-upload-name')).toBeVisible();
  await expect(page.locator('.ff-upload-name')).not.toHaveText('');
  await expect(page.locator('.ff-upload-count')).toHaveText(/\([12]\/2\)/);

  await page.unroute('**/api/fm/upload', holdUpload);
  // Both files finish and land in the grid.
  await expect(cardByName(page, a)).toBeVisible({ timeout: 15_000 });
  await expect(cardByName(page, b)).toBeVisible({ timeout: 15_000 });
});

test('bulk download: a multi-file selection offers a single Download ZIP', async ({ page }) => {
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

  // Multi-select hides the individual "Download" (browsers block N parallel
  // downloads) in favour of a single archive.
  await expect(page.getByRole('button', { name: 'Download', exact: true })).toHaveCount(0);

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download ZIP', exact: true }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/\.zip$/);
});

test('expired session: the load-error Retry recovers after a token refresh', async ({ page }) => {
  // Regression: when the session expires and the auto-refresh budget is spent,
  // the load-error Retry used to be a no-op (it never re-asked the host for a
  // token). It must reset the budget, re-request a token, and recover.
  const fresh = mintToken();
  const expired = fresh.slice(0, fresh.lastIndexOf('.') + 1) + 'BROKENSIGdeadbeef';

  // The host fixture embeds the iframe and answers FM_TOKEN_REFRESH with ?fresh=.
  await page.goto(
    `/tests/manual/test-auth-host.html?endpoint=&refresh=0` +
    `&token=${encodeURIComponent(expired)}&fresh=${encodeURIComponent(fresh)}`
  );

  const fm = page.frameLocator('#fm');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  // Model the user's stuck state: an attached (expired) token, budget
  // exhausted, a persistent load error, then make the host able to hand back
  // the fresh token. `token` must be set explicitly here — the iframe is
  // embedded (window.parent !== window), and the embedded boot path only ever
  // picks up a token from an FM_CONFIG postMessage, never the iframe src's own
  // `?token=` query string (that's a standalone-only convenience), so the
  // live session being modeled has to be injected directly.
  await page.evaluate((tok) => {
    const w = (document.getElementById('fm') as HTMLIFrameElement).contentWindow as any;
    const data = w.Alpine.$data(w.document.querySelector('.ff-app'));
    data.token = tok;
    data._refreshAttempts = 9;
    data._refreshPromise = null;
    data.loading = false;
    data.authRequired = false;
    data.authState = 'ok';
    data.loadError = 'Session expired';
    data.folders = []; data.files = [];
    (window as any).__hostRefreshEnabled = true;
  }, expired);

  await expect(fm.locator('.ff-load-error')).toBeVisible();
  await fm.locator('.ff-load-error .ff-empty-cta').click();   // Retry

  // Recovered: error cleared, budget reset, token swapped to the fresh one.
  await expect.poll(async () => page.evaluate(() => {
    const w = (document.getElementById('fm') as HTMLIFrameElement).contentWindow as any;
    const data = w.Alpine.$data(w.document.querySelector('.ff-app'));
    return data.loadError === null && data._refreshAttempts === 0 && data.authRequired === false
      && data.token.endsWith('deadbeef') === false;
  }), { timeout: 10_000 }).toBe(true);

  await expect(fm.locator('.ff-load-error')).toBeHidden();
});

test('URL import: button hidden without the claim, shown with it', async ({ page }) => {
  // Default token: no allow_url_import → the toolbar button is hidden (x-show).
  await page.goto(`/public/index.html?token=${mintToken()}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('.ff-toolbar .tb-btn', { hasText: 'Import URL' })).toBeHidden();

  // Import-enabled token → button visible.
  await page.goto(`/public/index.html?token=${mintTokenWithClaims({ allow_url_import: true })}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('.ff-toolbar .tb-btn', { hasText: 'Import URL' })).toBeVisible();
});

test('URL import: dialog opens and an SSRF URL shows a human-readable error', async ({ page }) => {
  await page.goto(`/public/index.html?token=${mintTokenWithClaims({ allow_url_import: true })}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  await page.locator('.ff-toolbar .tb-btn', { hasText: 'Import URL' }).click();
  await expect(page.locator('.ff-import-modal')).toBeVisible();

  // A loopback URL is blocked by the SSRF guard before any fetch → deterministic.
  await page.locator('.ff-import-input').fill('http://127.0.0.1/secret');
  await page.locator('.ff-import-modal .ff-btn-primary').click();

  const err = page.locator('.ff-import-error');
  await expect(err).toBeVisible({ timeout: 10_000 });
  await expect(err).toContainText(/public URLs/i);     // human message, not "ssrf_blocked"
  await expect(err).not.toContainText('ssrf_blocked');
});
