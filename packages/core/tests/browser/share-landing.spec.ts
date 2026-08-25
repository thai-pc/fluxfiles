import { test, expect } from '@playwright/test';

// The Share public landing (`/public/share.html`) — the FREE shell.
//
// The harness server is the stock `php -S router.php`, so the paid `share` module
// is absent and every real payload comes back as `501 module_not_installed`. That
// covers the degradation path for real; the rendering contract (card, password
// prompt, preview, download URL) is driven with a mocked `/api/fm/share/info`,
// exactly like media-preview.spec.ts mocks `/presign`. Nothing here needs the paid
// package — the shell is core's code and this is core's contract with it:
// it renders ONLY what the server sent, and never invents a card.

const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.share-token.sig';

/** A minimal info payload, in the shape ff_share_payload() emits. */
function card(over: Record<string, unknown> = {}) {
  const file = {
    name: 'q3-report.pdf',
    size: 184320,
    mime: 'application/pdf',
    kind: 'pdf',
    preview_url: null,
    ...(over.file as Record<string, unknown> | undefined),
  };
  return {
    label: 'Q3 numbers',
    expires: Math.floor(Date.now() / 1000) + 3600,
    has_password: false,
    downloads: 2,
    max_downloads: 5,
    remaining: 3,
    file,
    files: [file],
    brand: null,
    ...over,
  };
}

test('no token in the URL → a terminal message, never a card', async ({ page }) => {
  await page.goto('/public/share.html');
  await expect(page.locator('#gate')).toBeVisible();
  await expect(page.locator('#gate')).toContainText('missing its token');
  await expect(page.locator('#view')).toBeHidden();
  await expect(page.locator('#lock')).toBeHidden();
});

test('free core (module absent): the real 501 renders as a terminal state, no card', async ({ page }) => {
  // Unmocked — this is the live server saying the module isn't installed.
  const requests: { url: string; auth: string | undefined }[] = [];
  page.on('request', (r) => {
    if (r.url().includes('/api/fm/share/')) {
      requests.push({ url: r.url(), auth: r.headers()['authorization'] });
    }
  });

  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#gate')).toBeVisible();
  await expect(page.locator('#gate')).toContainText('not installed');
  await expect(page.locator('#view')).toBeHidden();
  await expect(page.locator('#lock')).toBeHidden();

  // The landing calls the PUBLIC route, and carries no Authorization header
  // (a recipient has no account and no main JWT).
  expect(requests.length).toBe(1);
  expect(requests[0].url).toContain('/api/fm/share/info?token=');
  expect(requests[0].auth).toBeUndefined();
});

test('card: name, size, expiry and remaining downloads come only from the server', async ({ page }) => {
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: card(), error: null }) })
  );
  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);

  await expect(page.locator('#view')).toBeVisible();
  await expect(page.locator('#name')).toHaveText('q3-report.pdf');
  await expect(page.locator('#label')).toHaveText('Q3 numbers');
  await expect(page.locator('#meta')).toContainText('180.0 KB');
  await expect(page.locator('#meta')).toContainText('3 download(s) left');
  // A capped share sends no preview_url → the shell must not invent one.
  await expect(page.locator('#preview')).toBeHidden();
});

test('download button hits the bytes route with dl=1 and the same token', async ({ page }) => {
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: card(), error: null }) })
  );
  const hits: string[] = [];
  await page.route('**/api/fm/share/file*', (route) => {
    hits.push(route.request().url());
    return route.abort(); // don't navigate away
  });

  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await page.locator('#download').click();
  await expect.poll(() => hits.length).toBe(1);
  expect(hits[0]).toContain(`token=${encodeURIComponent(TOKEN)}`);
  expect(hits[0]).toContain('dl=1');
  expect(hits[0]).not.toContain('password');
});

test('protected share: nothing but the prompt until unlock, then the card + a grant', async ({ page }) => {
  const expires = Math.floor(Date.now() / 1000) + 3600;
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      // Exactly what the module returns pre-unlock: no name, no size, no preview.
      body: JSON.stringify({ data: { has_password: true, expires, brand: null }, error: null }),
    })
  );
  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);

  await expect(page.locator('#lock')).toBeVisible();
  await expect(page.locator('#view')).toBeHidden();
  expect(await page.locator('body').innerText()).not.toContain('q3-report.pdf');

  // Wrong password → the error only; still no filename anywhere in the DOM.
  await page.route('**/api/fm/share/unlock', (route) =>
    route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ data: null, error: 'Password required', error_code: 'share_password' }),
    })
  );
  await page.locator('#pw').fill('wrong');
  await page.locator('#unlock').click();
  await expect(page.locator('#lockmeta')).toContainText('Password required');
  await expect(page.locator('#view')).toBeHidden();
  expect(await page.locator('body').innerText()).not.toContain('q3-report.pdf');

  // Right password → the card, and the download now carries the grant.
  await page.unroute('**/api/fm/share/unlock');
  await page.route('**/api/fm/share/unlock', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...card({ has_password: true }), grant: 'GRANT.TOK.EN', grant_expires: expires }, error: null }),
    })
  );
  const hits: string[] = [];
  await page.route('**/api/fm/share/file*', (route) => {
    hits.push(route.request().url());
    return route.abort();
  });

  await page.locator('#pw').fill('s3cret');
  await page.locator('#unlock').click();
  await expect(page.locator('#view')).toBeVisible();
  await expect(page.locator('#name')).toHaveText('q3-report.pdf');

  await page.locator('#download').click();
  await expect.poll(() => hits.length).toBe(1);
  expect(hits[0]).toContain('g=GRANT.TOK.EN');
  // The password itself is never in a URL — that's the whole point of the grant.
  expect(hits[0]).not.toContain('s3cret');
});

test('download button is disabled when remaining downloads is 0', async ({ page }) => {
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: card({ downloads: 5, max_downloads: 5, remaining: 0 }), error: null }),
    })
  );
  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#download')).toBeDisabled();
  await expect(page.locator('#download')).toHaveText('No downloads remaining');
  await expect(page.locator('#meta')).toContainText('0 download(s) left');
});

test('a failing download shows the error on the card, not a raw JSON navigation', async ({ page }) => {
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: card(), error: null }) })
  );
  await page.route('**/api/fm/share/file*', (route) =>
    route.fulfill({
      status: 410,
      contentType: 'application/json',
      body: JSON.stringify({ data: null, error: 'This link has been used up.', error_code: 'share_exhausted' }),
    })
  );
  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await page.locator('#download').click();

  await expect(page.locator('#dlerr')).toContainText('used up');
  // The main document never navigated away to the raw JSON body.
  expect(page.url()).toContain('/public/share.html');
  await expect(page.locator('#view')).toBeVisible();
  await expect(page.locator('#download')).toBeDisabled();
  await expect(page.locator('#download')).toHaveText('No downloads remaining');
});

test('an invalid grant on download re-locks the card instead of a dead end', async ({ page }) => {
  const expires = Math.floor(Date.now() / 1000) + 3600;
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { has_password: true, expires, brand: null }, error: null }),
    })
  );
  await page.route('**/api/fm/share/unlock', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...card({ has_password: true }), grant: 'GRANT.TOK.EN', grant_expires: expires }, error: null }),
    })
  );
  await page.route('**/api/fm/share/file*', (route) =>
    route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ data: null, error: 'Your unlock expired.', error_code: 'share_grant_invalid' }),
    })
  );

  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await page.locator('#pw').fill('s3cret');
  await page.locator('#unlock').click();
  await expect(page.locator('#view')).toBeVisible();

  await page.locator('#download').click();
  await expect(page.locator('#lock')).toBeVisible();
  await expect(page.locator('#view')).toBeHidden();
  await expect(page.locator('#lockmeta')).toContainText('expired');
  await expect(page.locator('#pw')).toHaveValue('');
});

test('a grant past its own expiry re-locks without ever hitting the server', async ({ page }) => {
  const alreadyExpired = Math.floor(Date.now() / 1000) - 5;
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { has_password: true, expires: alreadyExpired + 3600, brand: null }, error: null }),
    })
  );
  await page.route('**/api/fm/share/unlock', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...card({ has_password: true }), grant: 'GRANT.TOK.EN', grant_expires: alreadyExpired }, error: null }),
    })
  );
  const hits: string[] = [];
  await page.route('**/api/fm/share/file*', (route) => {
    hits.push(route.request().url());
    return route.abort();
  });

  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await page.locator('#pw').fill('s3cret');
  await page.locator('#unlock').click();
  await expect(page.locator('#view')).toBeVisible();

  await page.locator('#download').click();
  await expect(page.locator('#lock')).toBeVisible();
  expect(hits.length).toBe(0); // caught client-side, never round-tripped to the server
});

test('preview: an image renders as <img>, a pdf as an <iframe>, and only when the server sent a URL', async ({ page }) => {
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: card({ file: { name: 'photo.png', mime: 'image/png', kind: 'image', size: 4096, preview_url: '/api/fm/img?token=IMGTOK&width=1200' } }),
        error: null,
      }),
    })
  );
  // The preview must go through /img (a bounded transform), never the bytes route.
  const previewHits: string[] = [];
  await page.route('**/api/fm/img*', (route) => {
    previewHits.push(route.request().url());
    return route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('') });
  });

  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#preview img')).toHaveCount(1);
  await expect(page.locator('#preview iframe')).toHaveCount(0);
  await expect.poll(() => previewHits.length).toBe(1);
  expect(previewHits[0]).not.toContain(TOKEN);

  // An UNCAPPED pdf previews through the bytes route, in an iframe.
  await page.unroute('**/api/fm/share/info*');
  await page.route('**/api/fm/share/info*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: card({ max_downloads: 0, remaining: null, file: { preview_url: `/api/fm/share/file?token=${encodeURIComponent(TOKEN)}` } }),
        error: null,
      }),
    })
  );
  await page.route('**/api/fm/share/file*', (route) => route.fulfill({ status: 200, contentType: 'application/pdf', body: '%PDF-1.4' }));
  await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
  await expect(page.locator('#preview iframe')).toHaveCount(1);
  await expect(page.locator('#preview img')).toHaveCount(0);
});

test('preview: a non-FluxFiles preview_url is never assigned to iframe.src', async ({ page }) => {
  // `brand` (and, later, other module-supplied values) is the declared seam for
  // paid-module content, and `iframe.src` EXECUTES a javascript: value — unlike
  // `img.src`. The shell therefore only accepts its own routes.
  for (const hostile of ['javascript:window.__pwned=1', 'data:text/html,<script>window.__pwned=1</script>', 'https://evil.example/x.pdf']) {
    await page.unroute('**/api/fm/share/info*').catch(() => {});
    await page.route('**/api/fm/share/info*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: card({ max_downloads: 0, remaining: null, file: { name: 'q3.pdf', mime: 'application/pdf', kind: 'pdf', size: 10, preview_url: hostile } }),
          error: null,
        }),
      })
    );
    await page.goto(`/public/share.html?token=${encodeURIComponent(TOKEN)}`);
    // The card still renders (the file is real) — only the preview is refused.
    await expect(page.locator('#name')).toHaveText('q3.pdf');
    await expect(page.locator('#preview iframe')).toHaveCount(0);
    await expect(page.locator('#preview')).toBeHidden();
    expect(await page.evaluate(() => (window as unknown as Record<string, unknown>).__pwned)).toBeUndefined();
  }
});

test('dark mode boots from the stored theme before first paint', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('fluxfiles_theme', 'dark'));
  await page.goto('/public/share.html');
  await expect(page.locator('html')).toHaveClass(/dark/);

  await page.addInitScript(() => localStorage.setItem('fluxfiles_theme', 'light'));
  await page.goto('/public/share.html');
  await expect(page.locator('html')).not.toHaveClass(/dark/);
});
