import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import React from 'react';
import { FluxFiles } from '../../../../react/src';

const ORIGIN = 'http://localhost';
const flush = () => new Promise((r) => setTimeout(r, 0));

function fromIframe(type: string, payload: any = {}) {
  window.dispatchEvent(new MessageEvent('message', {
    origin: ORIGIN,
    data: { source: 'fluxfiles', type, v: 1, id: 'x', payload },
  }));
}

function setup(props: any = {}) {
  const { container } = render(
    React.createElement(FluxFiles, { endpoint: ORIGIN, token: 'JWT', ...props })
  );
  const iframe = container.querySelector('iframe') as HTMLIFrameElement;
  const sent: any[] = [];
  (iframe.contentWindow as any).postMessage = (msg: any) => sent.push(msg);
  return { iframe, sent };
}

describe('<FluxFiles> React wrapper', () => {
  beforeEach(() => cleanup());

  it('renders an iframe pointing at the endpoint UI', () => {
    const { iframe } = setup();
    expect(iframe).toBeTruthy();
    expect(iframe.getAttribute('src')).toBe(ORIGIN + '/public/index.html');
  });

  it('FM_READY → posts FM_CONFIG with the token', () => {
    const { sent } = setup({ token: 'ABC', disk: 's3' });
    fromIframe('FM_READY');
    const cfg = sent.find((m) => m.type === 'FM_CONFIG');
    expect(cfg).toBeTruthy();
    expect(cfg.payload.token).toBe('ABC');
  });

  it('FM_SELECT → calls onSelect with the payload', () => {
    const onSelect = vi.fn();
    const { } = setup({ onSelect });
    fromIframe('FM_SELECT', { url: 'https://cdn/a.png', key: 'a.png' });
    expect(onSelect).toHaveBeenCalledWith({ url: 'https://cdn/a.png', key: 'a.png' });
  });

  it('FM_TOKEN_REFRESH → onTokenRefresh → FM_TOKEN_UPDATED', async () => {
    const onTokenRefresh = vi.fn().mockResolvedValue('NEW_JWT');
    const { sent } = setup({ onTokenRefresh });
    fromIframe('FM_TOKEN_REFRESH', { reason: '401' });
    await flush(); await flush();
    expect(onTokenRefresh).toHaveBeenCalled();
    const upd = sent.find((m) => m.type === 'FM_TOKEN_UPDATED');
    expect(upd?.payload.token).toBe('NEW_JWT');
  });

  it('ignores messages from a foreign origin', () => {
    const onSelect = vi.fn();
    setup({ onSelect });
    window.dispatchEvent(new MessageEvent('message', {
      origin: 'https://evil.example.com',
      data: { source: 'fluxfiles', type: 'FM_SELECT', payload: { url: 'x' } },
    }));
    expect(onSelect).not.toHaveBeenCalled();
  });
});
