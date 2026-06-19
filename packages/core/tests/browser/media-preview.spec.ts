import { test, expect } from '@playwright/test';
import { mintToken, mintTokenWithClaims, openManager } from './helpers';

// M1 — media preview auto-refresh.
//
// S3/R2 GET URLs are presigned and expire (default ~1h). When a <video>/<audio>
// errors mid-playback the UI must silently re-presign (POST /api/fm/presign) and
// swap the src; local/static URLs never expire and must NOT trigger a re-presign.
//
// The browser harness runs on a local disk (no real presigned URLs), so we drive
// the component method directly with a mocked /presign — this exercises the exact
// refresh path a 403 would take, deterministically.

test('expiring media URL is silently re-presigned and swapped', async ({ page }) => {
  await openManager(page, mintToken());

  let presignBody: Record<string, unknown> | null = null;
  await page.route('**/api/fm/presign', async (route) => {
    presignBody = route.request().postDataJSON();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { url: 'https://cdn.example/fresh.mp4', expires_at: 9999999999 } }),
    });
  });

  const result = await page.evaluate(async (oldUrl) => {
    const comp = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const el = document.createElement('video');
    el.src = oldUrl;
    const file = { key: 'album/clip.mp4', url: oldUrl };
    await comp.refreshMediaSrc(file, el);
    return { url: file.url };
  }, 'https://s3.example/album/clip.mp4?X-Amz-Signature=expired');

  // The model URL was swapped to the freshly presigned one…
  expect(result.url).toBe('https://cdn.example/fresh.mp4');
  // …and /presign was asked for exactly this file as a GET.
  expect(presignBody).toMatchObject({ disk: 'local', path: 'album/clip.mp4', method: 'GET' });
});

test('static (non-expiring) media URL is not re-presigned', async ({ page }) => {
  await openManager(page, mintToken());

  let presignCalls = 0;
  await page.route('**/api/fm/presign', async (route) => {
    presignCalls++;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { url: 'should-not-be-used' } }),
    });
  });

  const result = await page.evaluate(async (staticUrl) => {
    const comp = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const el = document.createElement('video');
    el.src = staticUrl;
    const file = { key: 'album/clip.mp4', url: staticUrl };
    await comp.refreshMediaSrc(file, el);
    return { url: file.url };
  }, '/storage/uploads/album/clip.mp4');

  // Local/static URL → no presign, URL untouched.
  expect(presignCalls).toBe(0);
  expect(result.url).toBe('/storage/uploads/album/clip.mp4');
});

test('re-presign retries are capped to avoid looping on an unplayable file', async ({ page }) => {
  await openManager(page, mintToken());

  let presignCalls = 0;
  await page.route('**/api/fm/presign', async (route) => {
    presignCalls++;
    // Always return an expiring URL so each swap still looks refreshable.
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { url: `https://s3.example/clip.mp4?X-Amz-Signature=v${presignCalls}` } }),
    });
  });

  await page.evaluate(async () => {
    const comp = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const el = document.createElement('video');
    el.src = 'https://s3.example/clip.mp4?X-Amz-Signature=expired';
    const file = { key: 'clip.mp4', url: el.src };
    // Simulate repeated error events on the same element.
    for (let i = 0; i < 5; i++) {
      el.src = file.url; // mirror Alpine re-binding the swapped URL
      await comp.refreshMediaSrc(file, el);
    }
  });

  // _ffRefreshTries caps at 2 → at most 2 presign calls for one element.
  expect(presignCalls).toBeLessThanOrEqual(2);
});

// ── M2: claims (media_preview / preview_url_ttl) ───────────────────────────

test('media_preview:false disables inline video/audio (images unaffected)', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ media_preview: false }));

  const can = await page.evaluate(() => {
    const comp = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return {
      video: comp.isPreviewable({ name: 'clip.mp4', type: 'file' }, 'video'),
      audio: comp.isPreviewable({ name: 'song.mp3', type: 'file' }, 'audio'),
      image: comp.isPreviewable({ name: 'pic.png', type: 'file' }, 'image'),
    };
  });

  expect(can.video).toBe(false);
  expect(can.audio).toBe(false);
  expect(can.image).toBe(true); // image preview is independent of the media claim
});

test('preview_url_ttl flows into the re-presign request (default 7200)', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ preview_url_ttl: 1800 }));

  let body: Record<string, unknown> | null = null;
  await page.route('**/api/fm/presign', async (route) => {
    body = route.request().postDataJSON();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { url: 'https://cdn.example/fresh.mp4' } }),
    });
  });

  await page.evaluate(async (oldUrl) => {
    const comp = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    const el = document.createElement('video');
    el.src = oldUrl;
    await comp.refreshMediaSrc({ key: 'clip.mp4', url: oldUrl }, el);
  }, 'https://s3.example/clip.mp4?X-Amz-Signature=expired');

  expect(body).toMatchObject({ ttl: 1800 });
});
