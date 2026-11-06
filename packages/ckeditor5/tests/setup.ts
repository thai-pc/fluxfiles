// jsdom has no ResizeObserver; CKEditor's toolbar grouping needs one to render.
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
(window as unknown as { ResizeObserver: unknown }).ResizeObserver = ResizeObserverStub;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = ResizeObserverStub;
(window as unknown as { scrollTo: unknown }).scrollTo = () => {};
