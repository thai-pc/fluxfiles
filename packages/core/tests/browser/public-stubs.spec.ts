import { test, expect } from '@playwright/test';

// `/public/share.html` and `/public/intake.html` are redirect stubs now: the recipient
// pages ship inside the share / intake modules and are served at `/api/fm/<id>/page`.
// Links issued before the move must keep working, with the token (query) and any
// fragment intact. The page route is mocked: the harness server has no module installed.

for (const id of ['share', 'intake']) {
  test(`/public/${id}.html redirects to ../api/fm/${id}/page keeping query and hash`, async ({ page }) => {
    let seen = '';
    await page.route(`**/api/fm/${id}/page**`, async (route) => {
      seen = new URL(route.request().url()).search;
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>module page</title><p id="m">module page</p>' });
    });
    await page.goto(`/public/${id}.html?token=T0K%2Fen&x=1#frag`);
    await expect(page.locator('#m')).toBeVisible();
    const url = new URL(page.url());
    expect(url.pathname).toBe(`/api/fm/${id}/page`);
    expect(url.search).toBe('?token=T0K%2Fen&x=1');
    expect(url.hash).toBe('#frag');
    expect(seen).toBe('?token=T0K%2Fen&x=1');
  });

  test(`/public/${id}.html is a no-referrer stub with a noscript link`, async ({ page }) => {
    const res = await page.request.get(`/public/${id}.html`);
    const html = await res.text();
    expect(html).toContain('<meta name="referrer" content="no-referrer">');
    expect(html).toContain(`../api/fm/${id}/page`);
    expect(html).toContain('<noscript>');
    expect(html.length).toBeLessThan(2000);
  });
}
