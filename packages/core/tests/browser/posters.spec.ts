import { test, expect } from '@playwright/test';
import { mintToken, openManager, cardByName } from './helpers';

// PDF / video poster thumbnails in the grid. The listing is mocked so the spec
// doesn't depend on Ghostscript/ffmpeg being installed on the CI runner; the
// server-side rendering is covered by tests/unit/test-media-poster.php.
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

test('a PDF/video with a poster shows it; one without keeps the icon', async ({ page }) => {
  await page.route('**/api/fm/list*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: { items: [
      { key: 'guide.pdf', name: 'guide.pdf', type: 'file', size: 10, url: '/x/guide.pdf', poster: PNG },
      { key: 'clip.mp4', name: 'clip.mp4', type: 'file', size: 10, url: '/x/clip.mp4', poster: PNG },
      { key: 'notes.pdf', name: 'notes.pdf', type: 'file', size: 10, url: '/x/notes.pdf' },
    ], next_cursor: null, total: 3, disk_driver: 'local' }, error: null }),
  }));
  await openManager(page, mintToken());

  await expect(cardByName(page, 'guide.pdf').locator('.ff-thumb-poster img')).toHaveAttribute('src', PNG);
  await expect(cardByName(page, 'clip.mp4').locator('.ff-thumb-poster.is-video')).toBeVisible();
  await expect(cardByName(page, 'notes.pdf').locator('.ff-thumb-poster')).toHaveCount(0);
  await expect(cardByName(page, 'notes.pdf').locator('.ff-thumb-icon')).toBeVisible();
});
