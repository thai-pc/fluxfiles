import { describe, it, expect, beforeEach, vi } from 'vitest';
import FluxFiles from '../fluxfiles.js';

const ORIGIN = 'http://localhost';

/** Dispatch an iframe→SDK message as if it came from the FluxFiles iframe. */
function fromIframe(type, payload = {}) {
  window.dispatchEvent(new MessageEvent('message', {
    origin: ORIGIN,
    data: { source: 'fluxfiles', type, v: 1, id: 'x', payload },
  }));
}

/** Grab the iframe the SDK created and capture its outgoing postMessages. */
function captureOutgoing() {
  const iframe = document.getElementById('fluxfiles-iframe');
  const sent = [];
  // contentWindow is a jsdom window; spy on postMessage to record SDK→iframe traffic.
  iframe.contentWindow.postMessage = (msg) => sent.push(msg);
  return sent;
}

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('FluxFiles SDK postMessage protocol', () => {
  beforeEach(() => {
    FluxFiles.close();
    document.body.innerHTML = '';
  });

  it('FM_READY → replies with FM_CONFIG carrying the token + disk', () => {
    FluxFiles.open({ endpoint: ORIGIN, token: 'JWT123', disk: 's3', disks: ['local', 's3'] });
    const sent = captureOutgoing();
    fromIframe('FM_READY');
    const cfg = sent.find((m) => m.type === 'FM_CONFIG');
    expect(cfg).toBeTruthy();
    expect(cfg.payload.token).toBe('JWT123');
    expect(cfg.payload.disk).toBe('s3');
  });

  it('FM_CONFIG carries maxUploadMb (MB) and maxFiles', () => {
    FluxFiles.open({ endpoint: ORIGIN, token: 't', maxUploadMb: 25, maxFiles: 3 });
    const sent = captureOutgoing();
    fromIframe('FM_READY');
    const cfg = sent.find((m) => m.type === 'FM_CONFIG');
    expect(cfg.payload.maxUploadMb).toBe(25);
    expect(cfg.payload.maxFiles).toBe(3);
  });

  it('deprecated maxSize (bytes) is converted to maxUploadMb (MB)', () => {
    FluxFiles.open({ endpoint: ORIGIN, token: 't', maxSize: 10 * 1024 * 1024 });
    const sent = captureOutgoing();
    fromIframe('FM_READY');
    const cfg = sent.find((m) => m.type === 'FM_CONFIG');
    expect(cfg.payload.maxUploadMb).toBe(10); // 10 MB
  });

  it('FM_SELECT → calls onSelect and closes the modal', () => {
    const onSelect = vi.fn();
    FluxFiles.open({ endpoint: ORIGIN, token: 't', onSelect });
    captureOutgoing();
    fromIframe('FM_SELECT', { url: 'https://cdn/x.png', key: 'x.png' });
    expect(onSelect).toHaveBeenCalledWith({ url: 'https://cdn/x.png', key: 'x.png' });
    expect(document.getElementById('fluxfiles-iframe')).toBeNull(); // closed
  });

  it('FM_TOKEN_REFRESH → onTokenRefresh resolves → FM_TOKEN_UPDATED', async () => {
    const onTokenRefresh = vi.fn().mockResolvedValue('NEW_JWT');
    FluxFiles.open({ endpoint: ORIGIN, token: 'OLD', onTokenRefresh });
    const sent = captureOutgoing();
    fromIframe('FM_TOKEN_REFRESH', { reason: '401' });
    await flush(); await flush();
    expect(onTokenRefresh).toHaveBeenCalledWith({ reason: '401' });
    const upd = sent.find((m) => m.type === 'FM_TOKEN_UPDATED');
    expect(upd).toBeTruthy();
    expect(upd.payload.token).toBe('NEW_JWT');
  });

  it('FM_TOKEN_REFRESH → onTokenRefresh resolves null → FM_TOKEN_FAILED', async () => {
    const onTokenRefresh = vi.fn().mockResolvedValue(null);
    FluxFiles.open({ endpoint: ORIGIN, token: 'OLD', onTokenRefresh });
    const sent = captureOutgoing();
    fromIframe('FM_TOKEN_REFRESH', {});
    await flush(); await flush();
    expect(sent.find((m) => m.type === 'FM_TOKEN_FAILED')).toBeTruthy();
    expect(sent.find((m) => m.type === 'FM_TOKEN_UPDATED')).toBeFalsy();
  });

  it('FM_TOKEN_REFRESH with no handler → FM_TOKEN_FAILED (no_handler)', async () => {
    FluxFiles.open({ endpoint: ORIGIN, token: 'OLD' });
    const sent = captureOutgoing();
    fromIframe('FM_TOKEN_REFRESH', {});
    await flush();
    const failed = sent.find((m) => m.type === 'FM_TOKEN_FAILED');
    expect(failed).toBeTruthy();
    expect(failed.payload.reason).toBe('no_handler');
  });

  it('concurrent FM_TOKEN_REFRESH is de-duped (handler called once)', async () => {
    let resolveFn;
    const onTokenRefresh = vi.fn().mockImplementation(() => new Promise((r) => { resolveFn = r; }));
    FluxFiles.open({ endpoint: ORIGIN, token: 'OLD', onTokenRefresh });
    captureOutgoing();
    fromIframe('FM_TOKEN_REFRESH', {});
    fromIframe('FM_TOKEN_REFRESH', {}); // second while first in-flight
    await flush();
    expect(onTokenRefresh).toHaveBeenCalledTimes(1);
    resolveFn('NEW');
    await flush();
  });

  it('ignores messages from a foreign origin', () => {
    const onSelect = vi.fn();
    FluxFiles.open({ endpoint: ORIGIN, token: 't', onSelect });
    captureOutgoing();
    window.dispatchEvent(new MessageEvent('message', {
      origin: 'https://evil.example.com',
      data: { source: 'fluxfiles', type: 'FM_SELECT', payload: { url: 'x' } },
    }));
    expect(onSelect).not.toHaveBeenCalled();
  });
});
