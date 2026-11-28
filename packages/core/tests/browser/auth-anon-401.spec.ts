import { test, expect } from '@playwright/test';

// Regression: api()'s 401 handler used to run the shared refresh/expiry
// machinery for EVERY 401, including one from a request that never carried a
// token in the first place. The standalone UI fires exactly this kind of
// anonymous, pre-login probe automatically: proGate() (used by the Activity
// Log's audit-export button, Links' share/intake tabs, and version-history
// entries — all rendered via x-show, so Alpine evaluates their getters even
// while hidden) calls loadLicense() -> api('GET', '/api/fm/license') the
// moment the page loads with no token, at top level (window.parent ===
// window), before any login has happened. The server correctly 401s an
// unauthenticated request, but the OLD client code treated that exactly like
// an existing session's token going stale mid-use: it flipped authState to
// 'refreshing', posted FM_TOKEN_REFRESH into the void (no host is listening
// on a standalone top-level page), waited out the 10s timeout, and finally
// landed on authState 'expired' — showing "session expired" on a page that
// never had a session, on top of the correct "no token" screen.
//
// The fix (assets/fm.js api(), `if (!this.token) throw new Error('Unauthorized')`)
// short-circuits before touching authState when no token was ever attached.
//
// Since core 1.8.2 `pro_hints` is opt-in, so a page with no token (no claims) never
// fires the license probe at all: assert that, and that authState stays 'missing'.
test('anonymous page: no license probe, and authState stays "missing"', async ({ page }) => {
  const licenseCalls: string[] = [];
  page.on('request', (r) => {
    if (r.url().includes('/api/fm/license')) licenseCalls.push(r.url());
  });

  await page.goto('/public/index.html?disk=local');
  await expect(page.locator('.ff-app')).toBeAttached();

  // Give any (buggy) probe or refresh cycle a moment to kick in before asserting.
  await page.waitForTimeout(1500);
  const s1 = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return { authState: c.authState };
  });
  expect(s1.authState).toBe('missing');
  expect(licenseCalls).toEqual([]);
});
