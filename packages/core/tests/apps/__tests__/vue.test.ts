import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FluxFiles from '../../../../vue/src/FluxFiles.vue';

const ORIGIN = 'http://localhost';

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

  it('FM_READY → posts FM_CONFIG with the token', () => {
    const { sent } = setup({ token: 'ABC', disk: 's3' });
    fromIframe('FM_READY');
    const cfg = sent.find((m) => m.type === 'FM_CONFIG');
    expect(cfg).toBeTruthy();
    expect(cfg.payload.token).toBe('ABC');
  });

  it('FM_SELECT → emits "select" with the payload', () => {
    const { wrapper } = setup();
    fromIframe('FM_SELECT', { url: 'https://cdn/a.png', key: 'a.png' });
    const ev = wrapper.emitted('select');
    expect(ev).toBeTruthy();
    expect(ev![0][0]).toEqual({ url: 'https://cdn/a.png', key: 'a.png' });
  });

  it('FM_READY → emits "ready"', () => {
    const { wrapper } = setup();
    fromIframe('FM_READY');
    expect(wrapper.emitted('ready')).toBeTruthy();
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
