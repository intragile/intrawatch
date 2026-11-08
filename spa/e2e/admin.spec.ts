import type { Browser, Locator, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { Secret, TOTP } from 'otpauth'
import { BASE_URL, CUSTOM_DOMAIN } from './env'

// One administrator walks through the product on a freshly started image. The steps share one page and
// run in order; each depends on what the previous one created.
test.describe.configure({ mode: 'serial' })

const OWNER = {
  name: 'E2E Owner',
  email: 'e2e@example.com',
  password: 'e2e-test-password',
}
const WORKSPACE = { name: 'E2E Ops', slug: 'e2e-ops' }
const MONITOR = 'Self check'
const CHANNEL = 'E2E hook'
const MAINTENANCE = 'E2E database upgrade'
const PAGE = { title: 'E2E status', slug: 'e2e' }
const SECTION = 'Core services'

let browser: Browser
let page: Page
let totpSecret = ''
const frames: string[] = []
const channelAuth: number[] = []

/** Opens a Vuetify select or autocomplete and picks the option with the given text. */
async function pick(field: Locator, option: string | RegExp, search?: string): Promise<void> {
  await field.click()
  if (search !== undefined)
    await field.pressSequentially(search)
  await page.getByRole('option', { name: option }).first().click()
}

/** The local wall-clock time `days` from now, in the format of a datetime-local input. */
function localDateTime(days: number, hour: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  d.setHours(hour, 0, 0, 0)
  const pad = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:00`
}

/** A TOTP code from a time step no earlier than `afterStep`, waiting for that step if needed. */
async function totpCode(afterStep = 0): Promise<{ code: string, step: number }> {
  const totp = new TOTP({
    secret: Secret.fromBase32(totpSecret),
    digits: 6,
    period: 30,
  })
  for (;;) {
    const now = Date.now()
    const step = Math.floor(now / 30_000)
    const left = 30_000 - (now % 30_000)
    // A code about to expire may reach the server in the next step; take the next one instead.
    if (step >= afterStep && left > 4_000) {
      return {
        code: totp.generate({ timestamp: now }),
        step,
      }
    }
    await page.waitForTimeout(Math.min(left + 250, 31_000))
  }
}

async function nav(name: string): Promise<void> {
  await page.getByRole('navigation').getByRole('link', { name, exact: true }).click()
}

async function openAccountMenu(): Promise<void> {
  await page.getByRole('button', { name: 'Account menu' }).click()
}

test.beforeAll(async ({ browser: shared }: { browser: Browser }) => {
  browser = shared
  page = await browser.newPage()
  // Every websocket frame and channel authorisation is kept, so the live steps can prove the source.
  page.on('websocket', (ws) => {
    ws.on('framereceived', frame => frames.push(String(frame.payload)))
  })
  page.on('response', (res) => {
    if (res.url().endsWith('/broadcasting/auth'))
      channelAuth.push(res.status())
  })
})

test.afterAll(async () => {
  await page?.close()
})

test('1. the setup wizard creates the owner and lands on the empty monitor list', async () => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/admin\/setup$/)
  await expect(page.getByRole('heading', { name: 'Welcome to IntraWatch' })).toBeVisible()

  await page.locator('input[name="name"]').fill(OWNER.name)
  await page.locator('input[name="email"]').fill(OWNER.email)
  await page.locator('input[name="password"]').fill(OWNER.password)
  await page.locator('input[name="password_confirmation"]').fill(OWNER.password)
  await page.getByRole('button', { name: 'Next' }).click()

  await page.locator('input[name="workspace_name"]').fill(WORKSPACE.name)
  await page.locator('[data-test="setup-submit"]').click()

  await expect(page).toHaveURL(new RegExp(`/admin/w/${WORKSPACE.slug}/monitors$`))
  await expect(page.getByText('No monitors yet.')).toBeVisible()
})

test('2. an HTTP monitor turns Up live, without a reload', async () => {
  // The workspace channel is private: its subscription must have been authorised over /broadcasting/auth.
  await expect.poll(() => channelAuth.includes(200), { message: 'channel authorisation returned 200' }).toBe(true)
  await expect.poll(() => frames.some(f => f.includes('pusher_internal:subscription_succeeded') && f.includes('private-workspace.')))
    .toBe(true)

  await page.locator('[data-test="new-monitor"]').click()
  await expect(page.getByRole('heading', { name: 'New monitor' })).toBeVisible()
  await page.locator('input[name="monitor_name"]').fill(MONITOR)
  await page.locator('input[name="interval_seconds"]').fill('20')
  await page.locator('input[name="config_url"]').fill('http://127.0.0.1:3000/up')

  // A marker on window survives client-side navigation but not a page load.
  await page.evaluate(() => {
    (globalThis as unknown as { e2eMarker: string }).e2eMarker = 'no-reload'
  })
  await page.locator('[data-test="save"]').click()

  await expect(page).toHaveURL(new RegExp(`/admin/w/${WORKSPACE.slug}/monitors/\\d+$`))
  await expect(page.getByRole('heading', { name: MONITOR })).toBeVisible()
  const header = page.locator('header').filter({
    has: page.getByRole('heading', { name: MONITOR }),
  })
  await expect(header.locator('.v-chip').filter({ hasText: /^\s*Up\s*$/ })).toBeVisible({ timeout: 90_000 })

  const id = page.url().match(/monitors\/(\d+)$/)?.[1]
  expect(frames.some(f => f.includes('monitor.status_changed') && f.includes(`\\"monitor_id\\":${id}`)), 'a status_changed broadcast for the monitor arrived over the websocket').toBe(true)
  expect(await page.evaluate(() => (globalThis as unknown as { e2eMarker?: string }).e2eMarker)).toBe('no-reload')
})

test('3. a webhook channel draft test send shows the provider error', async () => {
  await nav('Notification channels')
  await page.getByRole('link', { name: 'New channel' }).click()
  await pick(page.locator('[data-test="channel-type"]'), 'Webhook')
  await page.locator('input[name="channel_name"]').fill(CHANNEL)
  // Nothing listens on port 1 inside the container, so the delivery is refused.
  await page.locator('input[name="config_url"]').fill('http://127.0.0.1:1/hook')

  await page.locator('[data-test="channel-test"]').click()
  await expect(page.locator('[data-test="test-result"]')).toHaveText(/Test failed/)
  const error = page.locator('[data-test="test-error"]')
  await expect(error).toBeVisible()
  await expect(error).not.toHaveText(/^\s*$/)
  await expect(error).not.toHaveText('The provider gave no details.')

  await page.locator('[data-test="channel-save"]').click()
  await expect(page.getByText('Channel created.')).toBeVisible()
})

test('4. a one-off maintenance is scheduled for the monitor', async () => {
  await nav('Maintenance')
  await page.getByRole('link', { name: 'Schedule maintenance' }).click()
  await page.locator('input[name="maintenance_title"]').fill(MAINTENANCE)
  // Tomorrow, so the window does not put the monitor into maintenance during the run.
  await page.locator('input[name="maintenance_starts_at"]').fill(localDateTime(1, 2))
  await page.locator('input[name="maintenance_ends_at"]').fill(localDateTime(1, 3))
  await pick(page.locator('[data-test="affected-monitors"] input').first(), MONITOR, 'Self')
  await page.keyboard.press('Escape')

  await page.locator('[data-test="maintenance-save"]').click()
  await expect(page.getByText('Maintenance scheduled.')).toBeVisible()
  await expect(page).toHaveURL(new RegExp(`/admin/w/${WORKSPACE.slug}/maintenances$`))
  const row = page.getByRole('row').filter({
    has: page.getByRole('link', { name: MAINTENANCE, exact: true }),
  })
  await expect(row).toContainText('Upcoming')
  await expect(row.getByRole('cell', { name: '1', exact: true })).toBeVisible()
})

test('5. a published status page with one section shows the monitor', async () => {
  await nav('Status pages')
  await page.getByRole('link', { name: 'New status page' }).click()
  await page.locator('input[name="status_title"]').fill(PAGE.title)
  await page.locator('input[name="status_slug"]').fill(PAGE.slug)
  await page.locator('input[name="status_published"]').check()
  await page.locator('input[name="status_custom_domain"]').fill(CUSTOM_DOMAIN)
  await page.locator('[data-test="status-page-save"]').click()

  await expect(page.getByText('Status page created.')).toBeVisible()
  await expect(page).toHaveURL(new RegExp(`/admin/w/${WORKSPACE.slug}/status-pages/\\d+$`))

  await page.locator('[data-test="section-add"]').click()
  await page.locator('input[name="section_name_0"]').fill(SECTION)
  await pick(page.locator('[data-test="item-picker-0"] input').first(), MONITOR, 'Self')
  await expect(page.locator('[data-test="item-0-0"]')).toBeVisible()
  await page.locator('[data-test="layout-save"]').click()
  await expect(page.getByText('Layout saved.')).toBeVisible()

  const response = await page.request.get(`/status/${PAGE.slug}`)
  expect(response.status()).toBe(200)
  // A separate, cookie-less page: the public page needs no session.
  const publicPage = await browser.newPage()
  try {
    await publicPage.goto(`/status/${PAGE.slug}`)
    await expect(publicPage.getByText(PAGE.title).first()).toBeVisible()
    await expect(publicPage.getByText(SECTION).first()).toBeVisible()
    await expect(publicPage.getByText(MONITOR).first()).toBeVisible()
  }
  finally {
    await publicPage.close()
  }
})

test('6. an Uptime Kuma database is analysed, imported, and its monitors show up', async () => {
  await nav('Import from Uptime Kuma')
  await expect(page).toHaveURL(/\/admin\/w\/e2e-ops\/import$/)

  await page.locator('input[name="import_file"]').setInputFiles('../tests/Fixtures/kuma/kuma2.db')
  await page.locator('[data-test="import-upload"]').click()

  // Analysis runs on the queue worker; the report appears once it is done.
  const run = page.locator('[data-test="import-run"]')
  await expect(run).toBeVisible({ timeout: 60_000 })
  await expect(page.locator('[data-test="import-counts"]')).toBeVisible()

  await run.click()
  await page.locator('[data-test="confirm"]').click()
  await expect(page.locator('[data-test="import-done"]')).toBeVisible({ timeout: 120_000 })

  await page.getByRole('link', { name: 'Open the monitors list' }).click()
  await expect(page).toHaveURL(new RegExp(`/admin/w/${WORKSPACE.slug}/monitors$`))
  // The imported monitor is a member of the imported group, which the list shows collapsed.
  await page.getByRole('button', { name: 'Show members of Websites' }).click()
  await expect(page.getByRole('link', { name: 'Example HTTP', exact: true })).toBeVisible()
})

test('7. two-factor sign-in: enable with a TOTP code, sign out, sign back in with a fresh code', async () => {
  await openAccountMenu()
  await page.getByRole('menuitem', { name: 'Account and security' }).or(page.getByRole('link', { name: 'Account and security' })).first().click()
  await expect(page).toHaveURL(/\/admin\/account$/)

  const secretResponse = page.waitForResponse(res => res.url().endsWith('/api/v1/auth/user/two-factor-secret-key'))
  await page.locator('[data-test="2fa-enable"]').click()
  // A session older than the confirmation timeout is asked for the password first.
  const dialogPassword = page.locator('input[name="confirm_password"]')
  const secret = await Promise.race([
    secretResponse.then(res => res.json() as Promise<{ secretKey: string }>),
    dialogPassword.waitFor().then(async () => {
      await dialogPassword.fill(OWNER.password)
      await page.getByRole('button', { name: 'Confirm', exact: true }).click()
      return (await secretResponse).json() as Promise<{ secretKey: string }>
    }),
  ])
  totpSecret = secret.secretKey
  expect(totpSecret).toMatch(/^[A-Z2-7]+=*$/)

  const first = await totpCode()
  await page.locator('input[name="totp_code"]').fill(first.code)
  await page.getByRole('button', { name: 'Confirm and turn on' }).click()
  await expect(page.locator('[data-test="codes-done"]')).toBeVisible()
  await page.locator('[data-test="codes-done"]').click()
  await expect(page.getByText('Signing in requires a code from your authenticator app.')).toBeVisible()

  await openAccountMenu()
  await page.getByText('Sign out', { exact: true }).click()
  await expect(page).toHaveURL(/\/admin\/login/)

  await page.locator('input[name="email"]').fill(OWNER.email)
  await page.locator('input[name="password"]').fill(OWNER.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/admin\/two-factor/)

  // A code is accepted once; the sign-in uses a later time step than the confirmation did.
  const second = await totpCode(first.step + 1)
  await page.locator('input[name="code"]').fill(second.code)
  await page.getByRole('button', { name: 'Verify' }).click()

  await expect(page).toHaveURL(new RegExp(`/admin/w/${WORKSPACE.slug}/monitors$`))
  await expect(page.getByRole('link', { name: MONITOR, exact: true })).toBeVisible()
})

test('8. on the custom domain only the status page is served; /admin/ is a 404', async () => {
  const domainBase = new URL(BASE_URL)
  domainBase.hostname = CUSTOM_DOMAIN
  const other = await browser.newPage()
  try {
    const root = await other.goto(new URL('/', domainBase).href)
    expect(root?.status()).toBe(200)
    await expect(other.getByText(PAGE.title).first()).toBeVisible()
    await expect(other.getByText(MONITOR).first()).toBeVisible()

    const admin = await other.goto(new URL('/admin/', domainBase).href)
    expect(admin?.status()).toBe(404)
    const login = await other.goto(new URL('/admin/login', domainBase).href)
    expect(login?.status()).toBe(404)
  }
  finally {
    await other.close()
  }
})
