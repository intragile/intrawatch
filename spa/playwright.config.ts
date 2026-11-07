import { defineConfig, devices } from '@playwright/test'
import { BASE_URL, CUSTOM_DOMAIN } from './e2e/env'

export default defineConfig({
  testDir: 'e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: true,
  globalTimeout: 10 * 60 * 1000,
  timeout: 8 * 60 * 1000,
  expect: { timeout: 15_000 },
  reporter: [
    ['list'],
  ],
  use: {
    baseURL: BASE_URL,
    locale: 'en-US',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: [`--host-resolver-rules=MAP ${CUSTOM_DOMAIN} 127.0.0.1`],
        },
      },
    },
  ],
})
