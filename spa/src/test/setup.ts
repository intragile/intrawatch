import { enableAutoUnmount } from '@vue/test-utils'
import { afterEach, beforeAll, vi } from 'vitest'
import { loadLocaleMessages } from '../i18n'

// Messages load lazily in the app; specs render translated text from the start.
beforeAll(async () => {
  await loadLocaleMessages('en')
})

// jsdom has no canvas; specs that need to observe the chart mock this module themselves.
vi.mock('uplot', () => ({
  default: class {
    static tzDate = (d: Date) => d
    setData(): void {}
    setSize(): void {}
    destroy(): void {}
  },
}))

class ResizeObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver

// uPlot reads the pixel ratio through matchMedia when it is imported.
if (typeof window.matchMedia !== 'function') {
  Object.defineProperty(window, 'matchMedia', {
    value: (query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
      onchange: null,
    }),
  })
}

// Vuetify overlays position themselves against the visual viewport, which jsdom lacks.
if (!('visualViewport' in window)) {
  Object.defineProperty(window, 'visualViewport', {
    value: Object.assign(new EventTarget(), {
      width: 1024,
      height: 768,
      offsetLeft: 0,
      offsetTop: 0,
      scale: 1,
    }),
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  try {
    localStorage.clear()
  }
  catch {}
  document.head.innerHTML = ''
  document.body.innerHTML = ''
})

// Registered last so it runs first: components unmount before the DOM is cleared.
enableAutoUnmount(afterEach)
