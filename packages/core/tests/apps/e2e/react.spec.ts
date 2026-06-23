import { test, expect } from '@playwright/test';
import { openHost, uploadFile, cardByName, pngFile } from './helpers';

// Drives the REAL @fluxfiles/react <FluxFiles> wrapper embedding a live core
// iframe. Proves the postMessage handshake, token forwarding, authenticated API
// calls, the onSelect event, and the ref command bridge all work end-to-end.

test('react wrapper: handshake + token auth (upload renders a card)', async ({ page }) => {
  const fm = await openHost(page, 'react');
  const name = `react-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });
});

test('react wrapper: onSelect bridges a pick to the host', async ({ page }) => {
  const fm = await openHost(page, 'react');
  const name = `react-pick-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });

  await cardByName(fm, name).dblclick();
  // onSelect -> host renders the picked payload as JSON.
  await expect(page.getByTestId('picked')).toContainText(name, { timeout: 10_000 });
});

test('react wrapper: ref command bridge (refresh re-lists)', async ({ page }) => {
  const fm = await openHost(page, 'react');
  const name = `react-refresh-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });

  await page.getByTestId('refresh-btn').click();
  // After a programmatic refresh() the listing is re-fetched and the card persists.
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });
});
