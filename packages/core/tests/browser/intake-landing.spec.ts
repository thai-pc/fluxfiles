import { test, expect } from '@playwright/test';

// The Intake public portal (`/public/intake.html`) — the FREE shell.
//
// Same pattern as share-landing.spec.ts: the harness server is the stock
// `php -S router.php`, so the paid `intake` module is absent and every real
// payload comes back as `501 module_not_installed`. That covers the
// degradation path for real; the brand-rendering contract (renderBrand/
// safeHttpUrl, ported verbatim from share.html) is driven with a mocked
// `/api/fm/intake/info`. This closes the DOM-level coverage gap noted in
// docs/INTAKE-BRANDING-ANALYTICS-DESIGN.md §A.9 — share.html's brand
// rendering already has this coverage (share-landing.spec.ts), intake.html's
// twin function did not.

const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.intake-token.sig';

/** A minimal info payload, in the shape the intake/info route emits. */
function info(over: Record<string, unknown> = {}) {
  return {
    label: 'Send us your files',
    has_password: false,
    allowed_ext: null,
    max_mb: null,
    remaining: null,
    brand: null,
    ...over,
  };
}

test('no token in the URL → a terminal message, never the form', async ({ page }) => {
  await page.goto('/public/intake.html');
  await expect(page.locator('#gate')).toBeVisible();
  await expect(page.locator('#gate')).toContainText('missing its token');
  await expect(page.locator('#form')).toBeHidden();
});

test('free core (module absent): the real 501 renders as a terminal state, no form', async ({ page }) => {
  await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#gate')).toBeVisible();
  await expect(page.locator('#gate')).toContainText('not installed');
  await expect(page.locator('#form')).toBeHidden();
});

test('brand: null renders nothing on the portal', async ({ page }) => {
  await page.route('**/api/fm/intake/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: info(), error: null }) })
  );
  await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#form')).toBeVisible();
  await expect(page.locator('#brand')).toBeHidden();
});

test('brand: name, logo and link render on the portal', async ({ page }) => {
  const brand = { name: 'Acme Corp', logo_url: 'https://acme.example/logo.png', color: '#123456', link_url: 'https://acme.example/' };
  await page.route('**/api/fm/intake/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: info({ brand }), error: null }) })
  );
  await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#form')).toBeVisible();
  await expect(page.locator('#brand')).toBeVisible();
  await expect(page.locator('#brand img')).toHaveAttribute('src', brand.logo_url);
  await expect(page.locator('#brand')).toContainText(brand.name);
  await expect(page.locator('#brand a')).toHaveAttribute('href', brand.link_url);
  await expect(page.locator('#brand a')).toHaveAttribute('target', '_blank');
  await expect(page.locator('#brand a')).toHaveAttribute('rel', 'noopener noreferrer');
  const accent = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim());
  expect(accent).toBe(brand.color);
});

test('brand: renders with logo+name but no link_url — no wrapping <a>', async ({ page }) => {
  const brand = { name: 'Acme Corp', logo_url: 'https://acme.example/logo.png', color: '', link_url: '' };
  await page.route('**/api/fm/intake/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: info({ brand }), error: null }) })
  );
  await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#brand')).toBeVisible();
  await expect(page.locator('#brand img')).toHaveAttribute('src', brand.logo_url);
  await expect(page.locator('#brand')).toContainText(brand.name);
  await expect(page.locator('#brand a')).toHaveCount(0);
});

test('brand: a javascript: logo_url is dropped, never reaches img.src', async ({ page }) => {
  const brand = { name: 'Evil', logo_url: 'javascript:alert(1)', color: '', link_url: '' };
  await page.route('**/api/fm/intake/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: info({ brand }), error: null }) })
  );
  await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#brand')).toBeVisible();
  await expect(page.locator('#brand img')).toHaveCount(0);
  await expect(page.locator('#brand')).toContainText(brand.name);
});

test('brand: hostile logo_url/link_url schemes never reach img.src/a.href', async ({ page }) => {
  for (const hostile of ['javascript:window.__pwned=1', 'data:text/html,<script>window.__pwned=1</script>', 'vbscript:msgbox(1)']) {
    await page.unroute('**/api/fm/intake/info*').catch(() => {});
    const brand = { name: 'Acme Corp', logo_url: hostile, color: '', link_url: hostile };
    await page.route('**/api/fm/intake/info*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: info({ brand }), error: null }) })
    );
    await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
    await expect(page.locator('#form')).toBeVisible();
    // Only the name is left — no logo, no link wrapper, since both were refused.
    await expect(page.locator('#brand img')).toHaveCount(0);
    await expect(page.locator('#brand a')).toHaveCount(0);
    await expect(page.locator('#brand')).toContainText(brand.name);
    expect(await page.evaluate(() => (window as unknown as Record<string, unknown>).__pwned)).toBeUndefined();
  }
});

test('brand: an invalid color is ignored, leaving --accent at its default', async ({ page }) => {
  for (const bad of ['not-a-color', 'red', '#12345', 'javascript:alert(1)']) {
    await page.unroute('**/api/fm/intake/info*').catch(() => {});
    const brand = { name: 'Acme Corp', logo_url: '', color: bad, link_url: '' };
    await page.route('**/api/fm/intake/info*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: info({ brand }), error: null }) })
    );
    await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
    // Default --accent is #7c3aed per the inline :root style — never overwritten by a bad value.
    const accent = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim());
    expect(accent.toLowerCase()).toBe('#7c3aed');
  }
});

test('the drop zone and file input are disabled when remaining uploads is 0', async ({ page }) => {
  await page.route('**/api/fm/intake/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: info({ remaining: 0 }), error: null }) })
  );
  await page.goto(`/public/intake.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#form')).toBeVisible();
  await expect(page.locator('#file')).toBeDisabled();
  await expect(page.locator('#constraints')).toContainText('0 upload(s) remaining');

  // Clicking the drop zone must not open the file picker while disabled — since a
  // real file chooser can't be asserted directly, assert no chooser event fires.
  let chooserFired = false;
  page.once('filechooser', () => { chooserFired = true; });
  await page.locator('#drop').click();
  await page.waitForTimeout(100);
  expect(chooserFired).toBe(false);
});
