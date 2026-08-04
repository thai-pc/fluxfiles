import { test, expect } from '@playwright/test';
import { mintToken, mintTokenWithClaims, openManager, uploadFile, pngFile, cardByName } from './helpers';

/**
 * Operator UI for the Share / Intake paid modules.
 *
 * The harness is a stock `php -S` with no private packages, so the modules are
 * GENUINELY absent — the 501 path is real, not mocked. What is mocked is only what
 * would require a licensed server (a successful create, a populated list).
 *
 * The three gating states are the point of this file. `proGate()` deliberately
 * departs from the optimize/terminal precedent (claim-gated, invisible when off) by
 * showing a locked affordance — but ONLY on an unlicensed, unframed server. Every
 * other combination must render nothing, and each of those is one silent regression
 * away from advertising a paid SKU inside someone's product.
 */

const LOCK = '.ff-pro-locked';
const LINKS_BTN = 'button[aria-label="Links"]';

test('locked: free core, claim off, top level → the Pro affordance, and no share API call', async ({ page }) => {
  const calls: string[] = [];
  page.on('request', (r) => {
    if (/\/api\/fm\/(share|intake)/.test(r.url())) calls.push(r.url());
  });

  await openManager(page, mintToken());
  await expect(page.locator(LOCK)).toBeVisible();

  await page.locator(LOCK).click();
  // The teaser names both features and links out; it must not call the API at all.
  await expect(page.getByRole('dialog', { name: 'Available in FluxFiles Pro' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Learn more' })).toBeVisible();
  expect(calls).toEqual([]);
});

test('hidden: pro_hints=false is a hard off switch — no affordance, no license call', async ({ page }) => {
  const licenseCalls: string[] = [];
  page.on('request', (r) => {
    if (r.url().includes('/api/fm/license')) licenseCalls.push(r.url());
  });

  await openManager(page, mintTokenWithClaims({ pro_hints: false }));
  await expect(page.locator('.ff-app')).toBeVisible();
  await expect(page.locator(LOCK)).not.toBeVisible();
  await expect(page.locator(LINKS_BTN)).not.toBeVisible();
  // The gate short-circuits before the license fetch — an operator who turned the
  // hint off should not pay a request to discover that.
  expect(licenseCalls).toEqual([]);
});

test('hidden: licensed but withheld → nothing renders (never sell against the operator)', async ({ page }) => {
  // The most likely silent regression: simplifying proGate to tokenAllows() alone
  // would turn this into a "locked" upsell inside a product that already paid.
  await page.route('**/api/fm/license*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { edition: 'pro', status: 'active', modules: ['share', 'intake'] },
        error: null,
      }),
    })
  );

  await openManager(page, mintToken());          // claim OFF, but the server is licensed
  await expect(page.locator('.ff-app')).toBeVisible();
  await expect(page.locator(LOCK)).not.toBeVisible();
  await expect(page.locator(LINKS_BTN)).not.toBeVisible();
});

test('hidden: framed → never advertise inside a resold product', async ({ page }) => {
  const token = mintToken();
  // setContent keeps the page's current URL as the base, so navigate to the real
  // origin first — against about:blank the relative iframe src would never load.
  await page.goto('/public/index.html');
  await page.setContent(
    `<iframe id="fm" style="width:1200px;height:800px;border:0"
       src="/public/index.html?token=${token}&disk=local"></iframe>`
  );
  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });
  await expect(frame.locator(LOCK)).not.toBeVisible();
  await expect(frame.locator(LINKS_BTN)).not.toBeVisible();
});

test('on + module absent: the real 501 renders inline as a terminal state', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_share: true, allow_intake: true }));
  await uploadFile(page, pngFile('share-me.png'));
  await expect(cardByName(page, 'share-me.png')).toBeVisible({ timeout: 15_000 });
  await cardByName(page, 'share-me.png').click();

  await page.getByRole('button', { name: 'Share link', exact: true }).click();
  await page.getByRole('button', { name: 'Create share link' }).click();

  // Localized (not the raw English server string), and NOT a transient toast: a 501
  // is not retryable, so the modal shows it in the body and drops the submit button.
  await expect(page.locator('.ff-links-error')).toContainText(/isn't installed|not installed/i);
  await expect(page.getByRole('button', { name: 'Create share link' })).toHaveCount(0);
});

test('create + one-shot: the url shows once, copies, and never lingers in the DOM', async ({ page }) => {
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
  const URL_ONCE = 'https://files.example/public/share.html?token=SECRET-TOKEN-VALUE';

  await page.route('**/api/fm/share', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { jti: 'j1', expires: Math.floor(Date.now() / 1000) + 3600, url: URL_ONCE, has_password: false },
        error: null,
      }),
    })
  );
  await page.route('**/api/fm/share/list*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      // The stored record carries NO token and NO url — that is the whole design.
      body: JSON.stringify({
        data: [{ jti: 'j1', disk: 'local', path: 'share-me.png', label: '', created: 1, expires: Math.floor(Date.now() / 1000) + 3600, views: 0, downloads: 0, max_downloads: 0, has_password: false }],
        error: null,
      }),
    })
  );

  await openManager(page, mintTokenWithClaims({ allow_share: true }));
  await uploadFile(page, pngFile('share-me.png'));
  await expect(cardByName(page, 'share-me.png')).toBeVisible({ timeout: 15_000 });
  await cardByName(page, 'share-me.png').click();

  await page.getByRole('button', { name: 'Share link', exact: true }).click();
  await page.getByRole('button', { name: 'Create share link' }).click();

  const field = page.locator('[x-ref="linkUrl"]');
  await expect(field).toHaveValue(URL_ONCE);
  await expect(page.locator('.ff-links-warn')).toBeVisible();

  await page.getByRole('button', { name: 'Copy', exact: true }).click();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(URL_ONCE);

  // Copied → closing must NOT prompt.
  page.on('dialog', (d) => { throw new Error(`unexpected confirm: ${d.message()}`); });
  await page.locator('.ff-links-done-close').click();
  await expect(page.locator('[x-ref="linkUrl"]')).not.toBeVisible();

  // After dismissal the token must exist nowhere in the document.
  await page.locator(LINKS_BTN).click();
  await expect(page.locator('.ff-activity-table:visible')).toHaveCount(1);
  expect(await page.content()).not.toContain('SECRET-TOKEN-VALUE');
  await expect(page.locator('.ff-links-hidden').first()).toBeVisible();
});

test('closing the reveal without copying asks for confirmation', async ({ page }) => {
  await page.route('**/api/fm/share', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { jti: 'j2', expires: Math.floor(Date.now() / 1000) + 3600, url: 'https://files.example/public/share.html?token=T2' },
        error: null,
      }),
    })
  );
  await openManager(page, mintTokenWithClaims({ allow_share: true }));
  await uploadFile(page, pngFile('confirm-me.png'));
  await expect(cardByName(page, 'confirm-me.png')).toBeVisible({ timeout: 15_000 });
  await cardByName(page, 'confirm-me.png').click();
  await page.getByRole('button', { name: 'Share link', exact: true }).click();
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();

  let asked = '';
  page.once('dialog', (d) => { asked = d.message(); d.dismiss(); });
  await page.locator('.ff-links-done-close').click();
  expect(asked).toMatch(/copied/i);
  // Dismissed → the link is still on screen, not silently lost.
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();
});

test('hostile url from a module is rendered as text, never executed', async ({ page }) => {
  // Mirrors share-landing.spec.ts: a module-supplied value is a declared seam, and
  // an <input value> / clipboard string can never execute — but an <a href> could,
  // so this pins the "text only" contract.
  await page.route('**/api/fm/share', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { jti: 'j3', expires: Math.floor(Date.now() / 1000) + 3600, url: 'javascript:window.__pwned=1' },
        error: null,
      }),
    })
  );
  await openManager(page, mintTokenWithClaims({ allow_share: true }));
  await uploadFile(page, pngFile('hostile.png'));
  await expect(cardByName(page, 'hostile.png')).toBeVisible({ timeout: 15_000 });
  await cardByName(page, 'hostile.png').click();
  await page.getByRole('button', { name: 'Share link', exact: true }).click();
  await page.getByRole('button', { name: 'Create share link' }).click();

  await expect(page.locator('[x-ref="linkUrl"]')).toHaveValue('javascript:window.__pwned=1');
  // No anchor and no iframe ever receives it.
  await expect(page.locator('.ff-links-modal a[href^="javascript:"]')).toHaveCount(0);
  expect(await page.evaluate(() => (window as unknown as { __pwned?: number }).__pwned)).toBeUndefined();
});

test('list: columns render, and Revoke confirms then re-fetches', async ({ page }) => {
  let listCalls = 0;
  const revoked: unknown[] = [];
  await page.route('**/api/fm/share/list*', (route) => {
    listCalls++;
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          { jti: 'keep-1', disk: 'local', path: 'docs/q3.pdf', label: 'Q3 report', created: 1, expires: Math.floor(Date.now() / 1000) + 3600, views: 9, downloads: 2, max_downloads: 5, has_password: true },
          { jti: 'old-1', disk: 'local', path: 'docs/old.pdf', label: 'Old', created: 1, expires: Math.floor(Date.now() / 1000) - 3600, views: 1, downloads: 1, max_downloads: 0, has_password: false },
        ],
        error: null,
      }),
    });
  });
  await page.route('**/api/fm/share/revoke', async (route) => {
    revoked.push(route.request().postDataJSON());
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { revoked: true }, error: null }) });
  });

  await openManager(page, mintTokenWithClaims({ allow_share: true }));
  await page.locator(LINKS_BTN).click();

  const rows = page.locator('.ff-activity-table tbody tr');
  await expect(rows).toHaveCount(2);
  await expect(rows.first()).toContainText('Q3 report');
  await expect(rows.first()).toContainText('2 / 5');
  // An expired record still lists, greyed with a badge, and stays revokable.
  await expect(rows.nth(1)).toHaveClass(/ff-links-row-expired/);
  await expect(rows.nth(1)).toContainText('Expired');

  const before = listCalls;
  page.once('dialog', (d) => d.accept());
  await rows.first().getByRole('button', { name: 'Revoke' }).click();
  await expect.poll(() => listCalls).toBeGreaterThan(before);
  expect(revoked).toEqual([{ disk: 'local', jti: 'keep-1' }]);
});
