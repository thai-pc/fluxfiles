import { test, expect } from '@playwright/test';
import { mintToken, openManager } from './helpers';

// Regression test for B1: the license status badge (toolbar dot) and its
// underlying GET /api/fm/license fetch must never fire while the standalone
// UI is embedded in an iframe (the normal adapter/embed path), even when an
// end-customer opens the "Storage usage" dashboard — the trigger that leaked
// it. License/subscription status is operator-only information; an embedded
// end-customer must never see it.
//
// fm.js guards this in two places (both load-bearing, both covered below):
//   - openUsage() only queues loadLicense() when window.parent === window.
//   - the licenseBadgeVisible getter itself short-circuits to false when framed,
//     independent of whatever loadLicense() may have already populated.
//
// The host harness mirrors interactions.spec.ts's "iframe init …" test: a tiny
// host page embeds /public/index.html and completes the FM_READY → FM_CONFIG
// handshake, which is the real boot path a framed instance takes (the bare
// `?token=` query-string path in helpers.openManager only applies when
// window.parent === window, so it can't be reused to boot the framed case).

test('license badge and /api/fm/license never fire when framed, even after opening Storage usage', async ({ page }) => {
  const token = mintToken();

  let licenseRequests = 0;
  await page.route('**/api/fm/license', (route) => {
    licenseRequests++;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { edition: 'pro', status: 'grace', enforcement: 'subscription', modules: [], days_left: 3 },
      }),
    });
  });

  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:900px;height:600px;border:0"></iframe>
    <script>
      window.addEventListener('message', function (e) {
        var m = e.data; if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY') {
          document.getElementById('fm').contentWindow.postMessage({
            source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'host-1',
            payload: { token: ${JSON.stringify(token)}, disk: 'local', endpoint: location.origin }
          }, '*');
        }
      });
    </script>
  </body></html>`;
  await page.route('**/__license_badge_host', (route) =>
    route.fulfill({ contentType: 'text/html', body: host })
  );
  await page.goto('/__license_badge_host');

  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  // Never visible right after boot, and no license fetch happened.
  await expect(frame.locator('.ff-license-badge-dot')).not.toBeVisible();
  expect(licenseRequests).toBe(0);

  // This is the exact B1 trigger: an embedded end-customer opening the usage
  // dashboard used to unconditionally kick off loadLicense(), which populated
  // licenseInfo and made the badge render persistently thereafter.
  await frame.getByRole('button', { name: 'Storage usage' }).click();
  await expect(frame.locator('.ff-usage-modal')).toBeVisible();

  // Still hidden, still zero license requests — both guards held.
  await expect(frame.locator('.ff-license-badge-dot')).not.toBeVisible();
  expect(licenseRequests).toBe(0);

  // No license banner content leaked into the (framed) usage modal either.
  await expect(frame.locator('.ff-license-banner')).toHaveCount(0);
});

// Companion positive case: prove the guard is specific to "framed", not a
// blanket "badge never works" regression — the legitimate standalone-admin
// path (top-level /public/, the Docker evaluation / self-hosted admin case)
// must still see the badge and be able to open the usage dashboard's license
// banner, mirroring usage.spec.ts's "license banner: edition + grace note"
// setup (routes registered before openManager(), since loadLicense() memoizes
// the first definitive answer and boot-time init already calls it eagerly at
// top level — see fm.js's initLocale()).
test('license badge appears at top level (not framed) when the license needs attention', async ({ page }) => {
  await page.route('**/api/fm/license', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { edition: 'pro', status: 'grace', enforcement: 'subscription', modules: [], days_left: 3 },
      }),
    })
  );

  await openManager(page, mintToken());

  // Boot-time loadLicense() (top-level only) resolves the grace status → badge shows.
  await expect(page.locator('.ff-license-badge-dot')).toBeVisible({ timeout: 5_000 });

  // The feature still functions end-to-end: opening Storage usage shows the
  // same status in the dashboard's license banner.
  await page.getByRole('button', { name: 'Storage usage' }).first().click();
  const banner = page.locator('.ff-license-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toContainText('grace period');
});

// Direct unit-style check of the getter's own defense-in-depth guard, isolated
// from whichever fetch path exists today. openUsage() already refuses to fetch
// license info while framed, so driving this scenario only through the UI
// (as in the first test above) can never exercise licenseBadgeVisible's own
// `if (window.parent !== window) return false;` line — that line is dead code
// from the UI's perspective as long as loadLicense() is never called while
// framed. It is exactly the "regardless of how licenseInfo got set" defense
// described in fm.js's comment, so it is tested the same way: populate
// licenseInfo directly (as some future/other code path might) and assert the
// getter still refuses to show the badge while framed.
test('licenseBadgeVisible getter refuses to show while framed even if licenseInfo is already populated', async ({ page }) => {
  const token = mintToken();

  const host = `<!doctype html><html><body>
    <iframe id="fm" src="/public/index.html" style="width:900px;height:600px;border:0"></iframe>
    <script>
      window.addEventListener('message', function (e) {
        var m = e.data; if (!m || m.source !== 'fluxfiles') return;
        if (m.type === 'FM_READY') {
          document.getElementById('fm').contentWindow.postMessage({
            source: 'fluxfiles', type: 'FM_CONFIG', v: 1, id: 'host-getter',
            payload: { token: ${JSON.stringify(token)}, disk: 'local', endpoint: location.origin }
          }, '*');
        }
      });
    </script>
  </body></html>`;
  await page.route('**/__license_badge_getter_host', (route) =>
    route.fulfill({ contentType: 'text/html', body: host })
  );
  await page.goto('/__license_badge_getter_host');

  const frame = page.frameLocator('#fm');
  await expect(frame.locator('.ff-app')).toBeVisible({ timeout: 15_000 });

  const fmFrame = page.frames().find((f) => f.url().includes('/public/index.html'));
  if (!fmFrame) throw new Error('framed index.html frame not found');

  const visible = await fmFrame.evaluate(() => {
    const comp = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    // Directly set the state a leaked/legacy fetch path would have produced.
    comp.licenseInfo = { edition: 'pro', status: 'grace', days_left: 3 };
    return comp.licenseBadgeVisible;
  });

  expect(visible).toBe(false);
});
