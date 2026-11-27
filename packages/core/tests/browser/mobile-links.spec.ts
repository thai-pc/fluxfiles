import { test, expect, type Page } from '@playwright/test';
import { mintTokenWithClaims, openManager } from './helpers';

/**
 * Phone-width Links panel: its 7-8 column tables used to scroll sideways inside the
 * panel, which hid the end of the Label column and the Revoke button. Under 640px each
 * row is now a stacked card, so every cell and the Revoke button are on screen with no
 * horizontal scroll; on a wide screen it stays a normal table.
 */
const ok = (data: unknown) => ({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, error: null }) });

async function openLinks(page: Page, mobile: boolean) {
  const now = Math.floor(Date.now() / 1000);
  await page.route('**/api/fm/intake/list*', (r) => r.fulfill(ok([
    { jti: 'p1', disk: 'local', path: 'in/northwind', label: 'Onboarding — Northwind Studio', created: 1, expires: now + 86400, received: 2, max_files: 0, allowed_ext: [], has_password: false, has_notify: false, notify_recipients: 0, notify_muted: false, request: null },
  ])));
  await openManager(page, mintTokenWithClaims({ allow_intake: true }));
  if (mobile) {
    await page.locator('.ff-mobile-more .ff-more-btn').click();
    await page.locator('.ff-more-menu').getByRole('button', { name: 'Links' }).click();
  } else {
    await page.locator('button[aria-label="Links"]').click();
  }
  await expect(page.locator('.ff-links-panel')).toBeVisible();
  await expect(page.locator('.ff-links-panel tbody td').first()).toContainText('Onboarding');
}

for (const vp of [{ width: 390, height: 844 }, { width: 540, height: 960 }]) {
  test(`mobile Links panel @${vp.width}: rows are stacked, nothing clipped or off-screen`, async ({ page }) => {
    await page.setViewportSize(vp);
    await openLinks(page, true);

    const m = await page.evaluate(() => {
      const panel = document.querySelector('.ff-links-panel') as HTMLElement;
      const p = panel.getBoundingClientRect();
      const body = panel.querySelector('.ff-activity-body') as HTMLElement;
      const first = panel.querySelector('tbody td') as HTMLElement;
      const btn = panel.querySelector('tbody td:last-child button')!.getBoundingClientRect();
      const f = first.getBoundingClientRect();
      return {
        pl: p.left, pr: p.right, vw: window.innerWidth,
        fl: f.left, fr: f.right,
        bl: btn.left, br: btn.right,
        sw: body.scrollWidth, cw: body.clientWidth,
        theadDisplay: getComputedStyle(panel.querySelector('thead')!).display,
        label: first.getAttribute('data-label'),
      };
    });
    expect(m.pl).toBeGreaterThanOrEqual(0);
    expect(m.pr).toBeLessThanOrEqual(m.vw);
    // First column cell is fully inside the panel (not clipped left or right).
    expect(m.fl).toBeGreaterThanOrEqual(m.pl);
    expect(m.fr).toBeLessThanOrEqual(m.pr);
    // The Revoke button is reachable without any horizontal scroll.
    expect(m.bl).toBeGreaterThanOrEqual(m.pl);
    expect(m.br).toBeLessThanOrEqual(m.pr);
    expect(m.sw).toBeLessThanOrEqual(m.cw);
    expect(m.theadDisplay).toBe('none');
    expect(m.label).toBe('Label');
  });
}

test('Links panel on desktop keeps the table layout', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await openLinks(page, false);
  await expect(page.locator('.ff-links-panel thead th').first()).toBeVisible();
  const display = await page.locator('.ff-links-panel tbody tr').first().evaluate((el) => getComputedStyle(el).display);
  expect(display).toBe('table-row');
});
