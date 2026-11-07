import { enableAutoUnmount } from '@vue/test-utils'
import { afterEach, vi } from 'vitest'

class ResizeObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver

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
