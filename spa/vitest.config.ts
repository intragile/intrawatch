import { configDefaults, defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config'

export default mergeConfig(viteConfig, defineConfig({
  test: {
    environment: 'jsdom',
    include: ['src/**/*.spec.ts'],
    // The Playwright suite in e2e/ runs against the image, not in jsdom.
    exclude: [...configDefaults.exclude, 'e2e/**'],
    setupFiles: ['src/test/setup.ts'],
    // The default 1s is too short for a lazily loaded view on a loaded machine (parallel runs).
    expect: {
      poll: { timeout: 10000 },
    },
    server: {
      deps: {
        inline: ['vuetify'],
      },
    },
  },
}))
