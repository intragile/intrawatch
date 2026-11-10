// Regenerates the screenshots of the user manual (spa/public/manual/{hu,en}/*.webp) from demo data.
//
// For each language it migrates a temporary SQLite database, seeds it with ManualScreenshotSeeder, serves the
// built admin with PHP's built-in server on a free port, signs in with Playwright and captures the screens the
// manual chapters show. The server and the database are always removed; any failure exits non-zero.
//
// Usage (from spa/): npm run manual:screenshots [-- --no-build] [-- --only=monitor-list]
import { Buffer } from 'node:buffer'
import { spawn, spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

const SPA = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const ROOT = resolve(SPA, '..')
const OUT = join(SPA, 'public', 'manual')
const LOCALES = ['hu', 'en']
const EMAIL = 'docs@example.test'
const PASSWORD = 'manual-screenshots'
const VIEWPORT = { width: 1280, height: 800 }
const TIMEZONE = 'Europe/Budapest'
/** WebP quality: UI text stays crisp while every image stays well under 200 KB. */
const QUALITY = 0.9
const MAX_BYTES = 200 * 1024
const STEP_TIMEOUT = 20_000

const args = process.argv.slice(2)
const build = !args.includes('--no-build')
const only = args.find(a => a.startsWith('--only='))?.slice('--only='.length).split(',')

// Animations, transitions and the blinking caret would make reruns differ; the live indicator reports the
// websocket, which this server does not run.
const STILL_CSS = `
*, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }
[data-test="live-indicator"] { visibility: hidden !important; }
`

let server = null
let workdir = null
let browser = null

function log(message) {
  console.log(`[manual:screenshots] ${message}`)
}

/** Runs a command to completion with a timeout; throws on a non-zero exit. */
function run(command, commandArgs, options = {}) {
  const result = spawnSync(command, commandArgs, { stdio: 'inherit', timeout: 600_000, ...options })
  if (result.error)
    throw result.error
  if (result.status !== 0)
    throw new Error(`${command} ${commandArgs.join(' ')} exited with ${result.status ?? result.signal}`)
}

function freePort() {
  return new Promise((ok, fail) => {
    const probe = createServer()
    probe.unref()
    probe.on('error', fail)
    probe.listen(0, '127.0.0.1', () => {
      const { port } = probe.address()
      probe.close(() => ok(port))
    })
  })
}

async function waitForServer(url, deadlineMs) {
  const deadline = Date.now() + deadlineMs
  for (;;) {
    try {
      const res = await fetch(url, {
        signal: AbortSignal.timeout(3_000),
      })
      if (res.ok)
        return
    }
    catch {}
    if (Date.now() > deadline)
      throw new Error(`${url} did not answer within ${deadlineMs / 1000}s`)
    await new Promise(r => setTimeout(r, 250))
  }
}

function messages(locale) {
  return JSON.parse(readFileSync(join(SPA, 'src', 'i18n', `${locale}.json`), 'utf8'))
}

/** Encodes a PNG screenshot as WebP with the browser's own encoder, so no image tool is needed. */
async function toWebp(page, png) {
  const dataUrl = await page.evaluate(async ({ b64, quality }) => {
    const img = new Image()
    img.src = `data:image/png;base64,${b64}`
    await img.decode()
    const canvas = document.createElement('canvas')
    canvas.width = img.naturalWidth
    canvas.height = img.naturalHeight
    canvas.getContext('2d').drawImage(img, 0, 0)
    return canvas.toDataURL('image/webp', quality)
  }, {
    b64: png.toString('base64'),
    quality: QUALITY,
  })
  if (!dataUrl.startsWith('data:image/webp;base64,'))
    throw new Error('the browser could not encode WebP')
  return Buffer.from(dataUrl.slice('data:image/webp;base64,'.length), 'base64')
}

/** The smallest rectangle around the given elements, padded and kept inside the page. */
async function around(page, locators, pad = 12) {
  const boxes = []
  for (const locator of locators) {
    await locator.scrollIntoViewIfNeeded({ timeout: STEP_TIMEOUT })
    const box = await locator.evaluate((el) => {
      const r = el.getBoundingClientRect()
      return {
        x: r.left + window.scrollX,
        y: r.top + window.scrollY,
        w: r.width,
        h: r.height,
      }
    })
    boxes.push(box)
  }
  const width = await page.evaluate(() => document.documentElement.scrollWidth)
  const x = Math.max(0, Math.floor(Math.min(...boxes.map(b => b.x)) - pad))
  const y = Math.max(0, Math.floor(Math.min(...boxes.map(b => b.y)) - pad))
  const right = Math.min(width, Math.ceil(Math.max(...boxes.map(b => b.x + b.w)) + pad))
  const bottom = Math.ceil(Math.max(...boxes.map(b => b.y + b.h)) + pad)
  return {
    x,
    y,
    width: right - x,
    height: bottom - y,
  }
}

/** The top of a form card, at most `maxHeight` tall, from the top of the page. */
async function formTop(page, card, maxHeight = 760) {
  await page.evaluate(() => window.scrollTo(0, 0))
  const box = await around(page, [card])
  return {
    ...box,
    height: Math.min(box.height, maxHeight),
  }
}

/** Waits until nothing is loading: no pending request, no progress bar, no skeleton. */
async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: STEP_TIMEOUT })
  await page.locator('.v-progress-linear--active, .v-progress-circular--indeterminate, .v-skeleton-loader')
    .first()
    .waitFor({ state: 'detached', timeout: STEP_TIMEOUT })
    .catch(async () => {
      if (await page.locator('.v-progress-linear--active, .v-progress-circular--indeterminate, .v-skeleton-loader').count() > 0)
        throw new Error('the page is still loading')
    })
  await page.evaluate(() => document.fonts.ready)
  await page.mouse.move(0, 0)
}

async function save(page, locale, name, options) {
  await settle(page)
  // Error banners and the login form must never end up in the manual.
  if (await page.locator('.v-alert--variant-tonal.text-error, .v-alert.bg-error').count() > 0)
    throw new Error(`${locale}/${name}: an error alert is visible`)
  if (page.url().includes('/login'))
    throw new Error(`${locale}/${name}: the session was lost (login page)`)
  const png = await page.screenshot({
    animations: 'disabled',
    caret: 'hide',
    scale: 'css',
    timeout: STEP_TIMEOUT,
    ...options,
  })
  const webp = await toWebp(page, png)
  if (webp.length > MAX_BYTES)
    throw new Error(`${locale}/${name}: ${webp.length} bytes is over the ${MAX_BYTES} byte budget; crop it`)
  mkdirSync(join(OUT, locale), { recursive: true })
  writeFileSync(join(OUT, locale, `${name}.webp`), webp)
  log(`${locale}/${name}.webp ${(webp.length / 1024).toFixed(1)} KB`)
}

async function pick(page, field, option) {
  await field.click({ timeout: STEP_TIMEOUT })
  await page.getByRole('option', { name: option }).first().click({ timeout: STEP_TIMEOUT })
  await page.keyboard.press('Escape')
}

const SHOTS = {
  /** Getting started: the whole screen after signing in, navigation included. */
  'overview': async function (page, { base, ws }) {
    await page.goto(`${base}/admin/w/${ws}/monitors`)
    await page.locator('[data-test="summary"]').waitFor({ timeout: STEP_TIMEOUT })
    await save(page, this.locale, 'overview')
  },

  /** Monitors: the list with the details of the degraded monitor beside it. */
  'monitor-list': async function (page, { base, ws, data }) {
    await page.goto(`${base}/admin/w/${ws}/monitors`)
    await page.getByText(data.degraded, { exact: true }).first().click({ timeout: STEP_TIMEOUT })
    await page.waitForURL(/\/monitors\/\d+$/, { timeout: STEP_TIMEOUT })
    // Without the navigation: the overview already shows it.
    await save(page, this.locale, 'monitor-list', {
      clip: {
        x: 256,
        y: 0,
        width: VIEWPORT.width - 256,
        height: VIEWPORT.height,
      },
    })
  },

  /** Monitors: the new-monitor form, with a name and an address typed in. */
  'monitor-new': async function (page, { base, ws }) {
    await page.goto(`${base}/admin/w/${ws}/monitors/new`)
    await page.locator('input[name="monitor_name"]').fill('Webshop', { timeout: STEP_TIMEOUT })
    await page.locator('input[name="config_url"]').fill('https://shop.example.com', { timeout: STEP_TIMEOUT })
    await page.locator('input[name="config_url"]').blur()
    const card = page.locator('.v-card').filter({
      has: page.locator('input[name="monitor_name"]'),
    }).first()
    await save(page, this.locale, 'monitor-new', { fullPage: true, clip: await formTop(page, card) })
  },

  /** Notifications: the channel editor for a new Slack channel. */
  'channel-new': async function (page, { base, ws }) {
    await page.goto(`${base}/admin/w/${ws}/channels/new`)
    await pick(page, page.locator('[data-test="channel-type"]'), /^Slack$/)
    await page.locator('input[name="channel_name"]').fill('#alerts', { timeout: STEP_TIMEOUT })
    await page.locator('input[name="channel_name"]').blur()
    const card = page.locator('.v-card').filter({
      has: page.locator('input[name="channel_name"]'),
    }).first()
    await save(page, this.locale, 'channel-new', { fullPage: true, clip: await formTop(page, card) })
  },

  /** Incidents: the timeline of an open incident and the card that posts the next update. */
  'incident-update': async function (page, { base, ws, t, data }) {
    await page.goto(`${base}/admin/w/${ws}/incidents`)
    await page.getByText(data.incident, { exact: true }).first().click({ timeout: STEP_TIMEOUT })
    await page.waitForURL(/\/incidents\/\d+$/, { timeout: STEP_TIMEOUT })
    const card = title => page.locator('.v-card').filter({
      has: page.getByRole('heading', { name: title, exact: true }),
    })
    await card(t.incidents.composer.title).waitFor({ timeout: STEP_TIMEOUT })
    await save(page, this.locale, 'incident-update', {
      fullPage: true,
      clip: await around(page, [
        card(t.incidents.timeline.title),
        card(t.incidents.composer.title),
      ]),
    })
  },

  /** Status pages: the layout editor with two sections. */
  'status-page-layout': async function (page, { base, ws, data }) {
    await page.goto(`${base}/admin/w/${ws}/status-pages`)
    await page.getByText(data.statusPage, { exact: true }).first().click({ timeout: STEP_TIMEOUT })
    await page.waitForURL(/\/status-pages\/\d+$/, { timeout: STEP_TIMEOUT })
    const card = page.locator('.v-card').filter({
      has: page.locator('[data-test="layout-save"]'),
    })
    await card.waitFor({ timeout: STEP_TIMEOUT })
    await save(page, this.locale, 'status-page-layout', { fullPage: true, clip: await around(page, [card]) })
  },
}

/** Texts of the seeded data the script clicks on, per language (see ManualScreenshotSeeder). */
const DATA = {
  hu: { degraded: 'Számlázás', incident: 'Késik a levelek kézbesítése', statusPage: 'Példa Kft. – rendszerállapot' },
  en: { degraded: 'Billing', incident: 'Email delivery delayed', statusPage: 'Example Inc. system status' },
}

async function capture(locale, base, now) {
  const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 1,
    locale: locale === 'hu'
      ? 'hu-HU'
      : 'en-US',
    timezoneId: TIMEZONE,
    colorScheme: 'light',
    reducedMotion: 'reduce',
  })
  try {
    // The clock stands still at the seeded "now", so relative times ("2 minutes ago") never drift.
    await context.clock.setFixedTime(new Date(now * 1000 + 30_000))
    await context.addInitScript(({ locale, css }) => {
      localStorage.setItem('intrawatch.locale', locale)
      localStorage.setItem('intrawatch.theme', 'light')
      document.addEventListener('DOMContentLoaded', () => {
        const style = document.createElement('style')
        style.textContent = css
        document.head.append(style)
      })
    }, { locale, css: STILL_CSS })
    context.setDefaultTimeout(STEP_TIMEOUT)
    context.setDefaultNavigationTimeout(STEP_TIMEOUT)

    const page = await context.newPage()
    const problems = []
    page.on('pageerror', e => problems.push(`page error: ${e.message}`))
    page.on('response', (res) => {
      if (res.status() >= 500)
        problems.push(`${res.status()} ${res.url()}`)
    })

    await page.goto(`${base}/admin/login`)
    await page.locator('input[name="email"], input[type="email"]').first().fill(EMAIL)
    await page.locator('input[type="password"]').first().fill(PASSWORD)
    await page.locator('button[type="submit"]').click()
    await page.waitForURL(/\/admin\/w\/[^/]+\/monitors/, { timeout: STEP_TIMEOUT })
    const ws = page.url().match(/\/admin\/w\/([^/]+)\//)[1]

    const ctx = {
      base,
      ws,
      t: messages(locale),
      data: DATA[locale],
      locale,
    }
    for (const [name, shot] of Object.entries(SHOTS)) {
      if (only && !only.includes(name))
        continue
      await shot.call(ctx, page, ctx)
      if (problems.length > 0)
        throw new Error(`${locale}/${name}: ${problems.join('; ')}`)
    }
  }
  finally {
    await context.close()
  }
}

async function main() {
  const unknown = (only ?? []).filter(name => !(name in SHOTS))
  if (unknown.length > 0)
    throw new Error(`unknown image name(s): ${unknown.join(', ')}; known: ${Object.keys(SHOTS).join(', ')}`)
  if (build) {
    log('building the admin (npm run build)')
    run('npm', ['run', 'build'], { cwd: SPA })
  }
  if (!existsSync(join(SPA, 'dist', 'index.html')))
    throw new Error('spa/dist is missing; run without --no-build')

  workdir = mkdtempSync(join(tmpdir(), 'intrawatch-manual-'))
  const database = join(workdir, 'manual.sqlite')
  const port = await freePort()
  const base = `http://localhost:${port}`
  // Seeded timestamps and the browser clock share this moment, rounded down so close reruns match. It is
  // deliberately not a fixed date: the server computes its own windows (status bars, maintenance calendar)
  // from its real clock, so the absolute dates shown in a few images change from one run to the next.
  const now = Math.floor(Date.now() / 600_000) * 600
  const env = {
    ...process.env,
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_KEY: `base64:${Buffer.from('manual-screenshots-dev-only-key!').toString('base64')}`,
    // localhost, not 127.0.0.1: the SPA's origin must match APP_URL for Sanctum's stateful session.
    APP_URL: base,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'log',
    MAIL_MAILER: 'log',
    LOG_CHANNEL: 'stderr',
    MANUAL_SEED_PASSWORD: PASSWORD,
    MANUAL_SEED_NOW: String(now),
  }

  browser = await chromium.launch()
  for (const locale of LOCALES) {
    log(`seeding the ${locale} demo data`)
    writeFileSync(database, '')
    run('php', [
      'artisan',
      'migrate:fresh',
      '--force',
      '--seed',
      '--seeder=Database\\Seeders\\ManualScreenshotSeeder',
      '--no-interaction',
    ], {
      cwd: ROOT,
      env: { ...env, MANUAL_SEED_LOCALE: locale },
      stdio: ['ignore', 'ignore', 'inherit'],
      timeout: 300_000,
    })
    if (!server) {
      log(`starting the server on ${base}`)
      // PHP's built-in server with Laravel's router script, what `artisan serve` runs, started directly so
      // killing it leaves no orphaned child behind.
      const router = join(ROOT, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php')
      server = spawn('php', [
        '-S',
        `localhost:${port}`,
        '-t',
        join(ROOT, 'public'),
        router,
      ], {
        // The router script takes the document root from the working directory.
        cwd: join(ROOT, 'public'),
        env: { ...env, PHP_CLI_SERVER_WORKERS: '4' },
        stdio: ['ignore', 'ignore', 'pipe'],
        // Its own process group: with PHP_CLI_SERVER_WORKERS the server forks workers, and cleanup stops the
        // whole group so none is left behind holding the port (or this script's stderr pipe) open.
        detached: true,
      })
      server.stderr.on('data', (chunk) => {
        const text = String(chunk)
        if (/ERROR|Exception|Fatal/i.test(text))
          process.stderr.write(text)
      })
      server.on('exit', (code) => {
        if (code !== null && code !== 0)
          log(`server exited with ${code}`)
      })
      await waitForServer(`${base}/up`, 60_000)
    }
    await capture(locale, base, now)
  }
}

async function cleanup() {
  await browser?.close().catch(() => {})
  if (server) {
    const group = -server.pid
    try {
      process.kill(group, 'SIGTERM')
      await new Promise(r => setTimeout(r, 500))
      process.kill(group, 'SIGKILL')
    }
    catch {} // ESRCH: the group is already gone.
    server.stderr?.destroy()
  }
  if (workdir)
    rmSync(workdir, { recursive: true, force: true })
}

// A hard deadline, so a wedged browser or server can never hang the script.
const watchdog = setTimeout(() => {
  console.error('[manual:screenshots] FAILED: exceeded 15 minutes')
  void cleanup().finally(() => process.exit(1))
}, 15 * 60_000)
watchdog.unref()

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    void cleanup().finally(() => process.exit(130))
  })
}

try {
  await main()
  await cleanup()
  log('done')
}
catch (error) {
  console.error(`[manual:screenshots] FAILED: ${error instanceof Error
    ? error.stack
    : error}`)
  await cleanup()
  process.exit(1)
}
