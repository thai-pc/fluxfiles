import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, cleanup, act } from '@testing-library/react';
import React from 'react';
import { FluxFiles, FluxFilesModal } from '../src';

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
    expect(iframe.getAttribute('src')).toBe(ORIGIN + '/public/index.html');
  });

  it('FM_READY → posts FM_CONFIG with token + disks + path + theme forwarded', () => {
    const { sent } = setup({ token: 'ABC', disk: 's3', disks: ['local', 's3'], path: 'photos', theme: 'dark' });
    fromIframe('FM_READY');
    const cfg = sent.find((m) => m.type === 'FM_CONFIG');
    expect(cfg?.payload.token).toBe('ABC');
    // Regression: disks/path/theme were dropped before (documented but not forwarded).
    expect(cfg?.payload.disks).toEqual(['local', 's3']);
    expect(cfg?.payload.path).toBe('photos');
    expect(cfg?.payload.theme).toBe('dark');
  });

  it('FM_CONFIG: disks defaults to [disk] when not given', () => {
    const { sent } = setup({ disk: 'r2' });
    fromIframe('FM_READY');
    expect(sent.find((m) => m.type === 'FM_CONFIG')?.payload.disks).toEqual(['r2']);
  });

  it('FM_SELECT → calls onSelect with the payload', () => {
    const onSelect = vi.fn();
    setup({ onSelect });
    fromIframe('FM_SELECT', { url: 'https://cdn/a.png', key: 'a.png' });
    expect(onSelect).toHaveBeenCalledWith({ url: 'https://cdn/a.png', key: 'a.png' });
  });

  it('FM_TOKEN_REFRESH → onTokenRefresh → FM_TOKEN_UPDATED', async () => {
    const onTokenRefresh = vi.fn().mockResolvedValue('NEW_JWT');
    const { sent } = setup({ onTokenRefresh });
    fromIframe('FM_TOKEN_REFRESH', { reason: '401' });
    await flush(); await flush();
    expect(onTokenRefresh).toHaveBeenCalled();
    expect(sent.find((m) => m.type === 'FM_TOKEN_UPDATED')?.payload.token).toBe('NEW_JWT');
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

describe('<FluxFilesModal> React wrapper', () => {
  beforeEach(() => cleanup());

  it('renders a visible Close button that calls onClose', () => {
    const onClose = vi.fn();
    const { container } = render(
      React.createElement(FluxFilesModal, { open: true, endpoint: ORIGIN, token: 'JWT', onClose })
    );
    const closeBtn = container.querySelector('button[aria-label="Close"]') as HTMLButtonElement;
    expect(closeBtn, 'modal has a Close button').toBeTruthy();
    closeBtn.click();
    expect(onClose).toHaveBeenCalled();
  });

  it('renders nothing when closed', () => {
    const { container } = render(
      React.createElement(FluxFilesModal, { open: false, endpoint: ORIGIN, token: 'JWT' })
    );
    expect(container.querySelector('iframe')).toBeNull();
    expect(container.querySelector('button[aria-label="Close"]')).toBeNull();
  });

  it('FM_THEME → re-themes the modal chrome (window + header) dark at runtime', async () => {
    const { container } = render(
      React.createElement(FluxFilesModal, { open: true, endpoint: ORIGIN, token: 'JWT', theme: 'light' })
    );
    const modalDiv = container.querySelector('[role="dialog"]')!.firstElementChild as HTMLElement;
    const header = modalDiv.firstElementChild as HTMLElement;
    const lightBg = modalDiv.style.background; // light boot resolution

    await act(async () => {
      fromIframe('FM_THEME', { theme: 'dark' });
    });

    expect(modalDiv.style.background).not.toBe(lightBg); // window recolored
    expect(header.style.background).toBe(modalDiv.style.background); // header matches

    await act(async () => {
      fromIframe('FM_THEME', { theme: 'light' });
    });
    expect(modalDiv.style.background).toBe(lightBg); // back to light
  });
});
