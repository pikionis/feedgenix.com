import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

/** Unmount React trees between tests to keep assertions tight. */
afterEach(() => {
  cleanup();
});

/**
 * jsdom doesn't implement matchMedia. Provide a minimal always-false stub so
 * components that probe color-scheme / reduced-motion don't crash.
 */
if (typeof window !== 'undefined' && !window.matchMedia) {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });
}

/**
 * jsdom has no IntersectionObserver. A stub lets components that watch for
 * scroll-into-view events (lazy-loaded sections, etc.) mount without errors.
 */
if (typeof window !== 'undefined' && !('IntersectionObserver' in window)) {
  class StubObserver {
    observe = vi.fn();
    unobserve = vi.fn();
    disconnect = vi.fn();
    takeRecords = vi.fn().mockReturnValue([]);
  }
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (window as any).IntersectionObserver = StubObserver;
}
