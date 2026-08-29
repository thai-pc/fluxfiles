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
test('anonymous pre-login license probe does not corrupt authState after its 401', async ({ page }) => {
  const licenseResponse = page.waitForResponse((res) => res.url().includes('/api/fm/license'));

  await page.goto('/public/index.html?disk=local');
  await expect(page.locator('.ff-app')).toBeAttached();

  // Confirm the anonymous probe actually happened and actually got a 401 —
  // otherwise this test would pass trivially by never exercising the bug.
  const res = await licenseResponse;
  expect(res.status()).toBe(401);

  const state = () =>
    page.evaluate(() => {
      const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
      return { authState: c.authState, licenseInfo: c.licenseInfo };
    });

  // Give any (buggy) refresh cycle a moment to kick in before asserting.
  await page.waitForTimeout(300);
  const s1 = await state();
  expect(s1.authState).toBe('missing');
  // loadLicense()'s catch fallback — proves the 401 was swallowed locally,
  // not silently retried into a refresh loop.
  expect(s1.licenseInfo).toEqual({ edition: 'free', status: 'free' });

  // The bug's failure mode is a *transition*, not just a bad final value —
  // it only surfaces after the 10s host-response timeout inside
  // _handleTokenExpired(). Wait past that window and confirm authState
  // never moved off 'missing' (and never for real, since 'refreshing' would
  // also flip it back to 'missing'-adjacent noise if we only checked once).
  await page.waitForTimeout(11_000);
  const s2 = await state();
  expect(s2.authState).toBe('missing');

  // The "expired" auth card (with its Retry/Close buttons) must never render.
  await expect(page.locator('.ff-auth-subtitle', { hasText: 'session has expired' })).toHaveCount(0);
});
