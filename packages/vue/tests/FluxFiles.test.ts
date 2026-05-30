import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import FluxFiles from '../src/FluxFiles.vue';

const ORIGIN = 'http://localhost';
const flush = () => new Promise((r) => setTimeout(r, 0));

function fromIframe(type: string, payload: any = {}) {
  window.dispatchEvent(new MessageEvent('message', {
    origin: ORIGIN,
    data: { source: 'fluxfiles', type, v: 1, id: 'x', payload },
  }));
}

function setup(props: any = {}) {
  const wrapper = mount(FluxFiles, { props: { endpoint: ORIGIN, token: 'JWT', ...props }, attachTo: document.body });
  const iframe = wrapper.find('iframe').element as HTMLIFrameElement;
  const sent: any[] = [];
  (iframe.contentWindow as any).postMessage = (msg: any) => sent.push(msg);
  return { wrapper, iframe, sent };
}

describe('<FluxFiles> Vue wrapper', () => {
  it('renders an iframe pointing at the endpoint UI', () => {
    const { iframe } = setup();
    expect(iframe.getAttribute('src')).toBe(ORIGIN + '/public/index.html');
  });

  it('FM_READY → posts FM_CONFIG with the token + emits "ready"', () => {
    const { wrapper, sent } = setup({ token: 'ABC' });
    fromIframe('FM_READY');
    expect(sent.find((m) => m.type === 'FM_CONFIG')?.payload.token).toBe('ABC');
    expect(wrapper.emitted('ready')).toBeTruthy();
  });

  it('FM_SELECT → emits "select" with the payload', () => {
    const { wrapper } = setup();
    fromIframe('FM_SELECT', { url: 'https://cdn/a.png', key: 'a.png' });
    expect(wrapper.emitted('select')?.[0][0]).toEqual({ url: 'https://cdn/a.png', key: 'a.png' });
  });

  it('FM_TOKEN_REFRESH → onTokenRefresh prop → FM_TOKEN_UPDATED', async () => {
    const onTokenRefresh = vi.fn().mockResolvedValue('NEW_JWT');
    const { sent } = setup({ onTokenRefresh });
    fromIframe('FM_TOKEN_REFRESH', { reason: '401' });
    await flush(); await flush();
    expect(onTokenRefresh).toHaveBeenCalled();
    expect(sent.find((m) => m.type === 'FM_TOKEN_UPDATED')?.payload.token).toBe('NEW_JWT');
  });

  it('ignores messages from a foreign origin', () => {
    const { wrapper } = setup();
    window.dispatchEvent(new MessageEvent('message', {
      origin: 'https://evil.example.com',
      data: { source: 'fluxfiles', type: 'FM_SELECT', payload: { url: 'x' } },
    }));
    expect(wrapper.emitted('select')).toBeFalsy();
  });
});
