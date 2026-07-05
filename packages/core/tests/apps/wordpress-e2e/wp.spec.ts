import { test, expect, type Page } from '@playwright/test';
import { pngFile, cardByName, uploadFile } from '../e2e/helpers';

const BASE = 'http://localhost:8888';

// wp-env's default admin credentials. WP login can need a second attempt (the
// test cookie / redirect timing), so submit and confirm via the admin bar rather
// than a strict URL match.
async function loginAdmin(page: Page) {
  for (let attempt = 0; attempt < 2; attempt++) {
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('#wpadminbar').count()) return; // already authenticated
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.click('#wp-submit'),
    ]);
    await page.goto(`${BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('#wpadminbar').count()) return;
  }
  throw new Error('WordPress admin login failed');
}

// Drives the REAL WordPress plugin: the [fluxfiles] shortcode mints a JWT for the
// logged-in WP user (FluxFilesPlugin), loads the bundled SDK, embeds the core UI,
// and every /api/fm/* call is proxied through the WP REST API (FluxFilesApi →
// core FileManager). This is the actual release artifact (built by build-wordpress.sh).
test('wordpress shortcode: embed boots + REST proxy auth (upload renders a card)', async ({ page }) => {
  await loginAdmin(page);
  await page.goto(`${BASE}/files/`);

  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 25_000 });

  const name = `wp-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });
});

// Attachment bridge (3-in-1): the /attach REST route must create a REAL WP attachment
// against the real WP DB, and the URL-rewrite filter must serve it from the FluxFiles
// URL. Exercised through the block editor (which carries a REST nonce), so this covers
// real wp_insert_attachment + WP_Query idempotency + wp_get_attachment_url — none of
// which the stubbed PHP smoke can prove.
test('wordpress attachment bridge: /attach creates an offloaded attachment (idempotent) + URL rewrite', async ({ page }) => {
  await loginAdmin(page);
  // The block editor page exposes wpApiSettings.nonce for authenticated REST calls.
  await page.goto(`${BASE}/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window as any).wpApiSettings?.nonce, null, { timeout: 20_000 });

  const url = `https://cdn.example.com/wp/e2e-${Date.now()}.jpg`;
  const key = url.split('/').slice(-2).join('/');

  const result = await page.evaluate(async ({ base, url, key }) => {
    const nonce = (window as any).wpApiSettings.nonce as string;
    const attach = () =>
      fetch(`${base}/wp-json/fluxfiles/v1/api/fm/attach`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ url, key, disk: 'local', name: key.split('/').pop(), mime: 'image/jpeg', alt: 'e2e alt' }),
      }).then((r) => r.json());

    const first = await attach();
    const second = await attach(); // idempotent on (disk,key) → same id
    const id = first?.data?.id;
    // REST media item reflects the URL-rewrite filter via source_url.
    const media = id
      ? await fetch(`${base}/wp-json/wp/v2/media/${id}`, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } }).then((r) => r.json())
      : null;
    return { firstId: id, secondId: second?.data?.id, sourceUrl: media?.source_url, altText: media?.alt_text };
  }, { base: BASE, url, key });

  expect(result.firstId).toBeGreaterThan(0);           // real attachment created
  expect(result.secondId).toBe(result.firstId);        // idempotent — no duplicate
  expect(result.sourceUrl).toBe(url);                  // URL rewritten to FluxFiles
  expect(result.altText).toBe('e2e alt');              // alt synced
});

// Native-picker integration (experimental): with fluxfiles_replace_picker on (set in
// setup.sh), the "From FluxFiles" button must be injected into WordPress's own wp.media
// modal — so Featured Image / core Image block / Customizer can reach FluxFiles. We open
// a media frame programmatically and assert the button appears (the fragile part is the
// injection; the select flow reuses the same attach path tested above).
test('wordpress native picker: "From FluxFiles" button is injected into wp.media', async ({ page }) => {
  await loginAdmin(page);
  await page.goto(`${BASE}/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded' });
  // Wait for the native media JS + our integration script to load.
  await page.waitForFunction(() => !!(window as any).wp?.media, null, { timeout: 20_000 });
  // Open a media frame programmatically (same modal Featured Image / blocks use).
  await page.evaluate(() => (window as any).wp.media({ title: 'e2e', multiple: false }).open());
  await expect(page.locator('.media-modal .fluxfiles-from-btn')).toBeVisible({ timeout: 15_000 });
});
