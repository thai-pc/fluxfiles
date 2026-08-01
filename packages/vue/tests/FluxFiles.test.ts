import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import FluxFiles from '../src/FluxFiles.vue';
import FluxFilesModal from '../src/FluxFilesModal.vue';

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

  it('FM_READY → posts FM_CONFIG with token + disks + path + theme forwarded', () => {
    const { wrapper, sent } = setup({ token: 'ABC', disk: 's3', disks: ['local', 's3'], path: 'photos', theme: 'dark' });
    fromIframe('FM_READY');
    const cfg = sent.find((m) => m.type === 'FM_CONFIG');
    expect(cfg?.payload.token).toBe('ABC');
    // Regression: disks/path/theme were dropped before (documented but not forwarded).
    expect(cfg?.payload.disks).toEqual(['local', 's3']);
    expect(cfg?.payload.path).toBe('photos');
    expect(cfg?.payload.theme).toBe('dark');
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

describe('<FluxFilesModal> Vue wrapper', () => {
  // The modal Teleports to <body>, so query the document, not the wrapper.
  it('renders a visible Close button that emits close + update:open', async () => {
    const wrapper = mount(FluxFilesModal, {
      props: { open: true, endpoint: ORIGIN, token: 'JWT' }, attachTo: document.body,
    });
    const closeBtn = document.body.querySelector('button[aria-label="Close"]') as HTMLButtonElement;
    expect(closeBtn, 'modal has a Close button').toBeTruthy();
    closeBtn.click();
    expect(wrapper.emitted('close')).toBeTruthy();
    expect(wrapper.emitted('update:open')?.[0]).toEqual([false]);
    wrapper.unmount();
  });

  it('locks body scroll when mounted already-open, restores on unmount', () => {
    document.body.style.overflow = ''; // clean slate
    const wrapper = mount(FluxFilesModal, {
      props: { open: true, endpoint: ORIGIN, token: 'JWT' }, attachTo: document.body,
    });
    // Regression: onMounted must apply the lock even though `open` never changed.
    expect(document.body.style.overflow).toBe('hidden');
    wrapper.unmount();
    expect(document.body.style.overflow).toBe('');
  });

  it('FM_THEME → re-themes the modal chrome (window + header) dark at runtime', async () => {
    const wrapper = mount(FluxFilesModal, {
      props: { open: true, endpoint: ORIGIN, token: 'JWT', theme: 'light' }, attachTo: document.body,
    });
    const closeBtn = document.body.querySelector('button[aria-label="Close"]') as HTMLElement;
    const header = closeBtn.parentElement as HTMLElement;
    const modalWin = header.parentElement as HTMLElement; // overlay > modalWin > header > button
    const lightBg = modalWin.style.background; // light boot resolution

    fromIframe('FM_THEME', { theme: 'dark' });
    await flush(); // macrotask runs after Vue's reactive DOM flush
    expect(modalWin.style.background).not.toBe(lightBg); // window recolored
    expect(header.style.background).toBe(modalWin.style.background); // header matches

    fromIframe('FM_THEME', { theme: 'light' });
    await flush();
    expect(modalWin.style.background).toBe(lightBg); // back to light
    wrapper.unmount();
  });
});
