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
