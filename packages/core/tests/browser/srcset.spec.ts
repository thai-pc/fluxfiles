import { test, expect } from '@playwright/test';
import { mintToken, imageFile, cardByName, openManager, uploadFile } from './helpers';

// Responsive srcset (M2 UI). The server emits img_srcset on image list entries
// (webp on + stream secret, both set by the standalone test env); the detail
// preview <img> binds it. We upload a real multi-pixel PNG (so a natural width is
// stored), open its detail panel, and assert the preview carries a srcset of
// /api/fm/img candidates with `w` descriptors.

test('detail preview image gets a responsive srcset from img_srcset', async ({ page }) => {
  await openManager(page, mintToken());

  const name = `srcset-${Date.now()}.png`;
  await uploadFile(page, imageFile(name, 1200, 800));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });

  // Open the detail panel for the uploaded image.
  await cardByName(page, name).click();
  const img = page.locator('.detail-thumb img');
  await expect(img).toBeVisible({ timeout: 10_000 });

  // srcset is present and is a list of /api/fm/img candidates with w descriptors.
  const srcset = await img.getAttribute('srcset');
  expect(srcset, 'detail image has a srcset').toBeTruthy();
  expect(srcset!).toContain('/api/fm/img?token=');
  expect(srcset!).toMatch(/\b\d+w/); // at least one width descriptor
  // No candidate exceeds the 1200px source (transform never upsizes).
  const widths = [...srcset!.matchAll(/\b(\d+)w/g)].map((m) => Number(m[1]));
  expect(widths.length).toBeGreaterThan(1);
  expect(Math.max(...widths)).toBeLessThanOrEqual(1200);

  // Default token sets no srcset_sizes → the UI supplies its own sizes fallback.
  expect(await img.getAttribute('sizes')).toBeTruthy();
});
