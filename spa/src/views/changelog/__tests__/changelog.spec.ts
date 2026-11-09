import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '../../../i18n'
import { click, mountComponent } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import ChangelogView from '../ChangelogView.vue'

function release(over: Record<string, unknown> = {}) {
  return {
    version: '1.2.0',
    date: '2026-03-05',
    url: null,
    unreleased: false,
    yanked: false,
    summary_html: '<p>Big <strong>summary</strong></p>',
    groups: [
      {
        name: 'Added',
        summary_html: null,
        items: [
          { html: '<em>Shiny</em> thing', text: 'Shiny thing' },
        ],
      },
    ],
    locale: 'en',
    fallback: false,
    ...over,
  }
}

function index(data: unknown[], current = 1, last = 1) {
  return {
    data,
    meta: {
      current_page: current,
      last_page: last,
      per_page: 1,
      total: data.length,
    },
    locale: 'en',
    fallback: false,
  }
}

describe('changelog view', () => {
  afterEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('renders version, localized date, summary, groups and items', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release(),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.body.textContent).toContain('1.2.0')
    expect(document.body.textContent).toContain('March 5, 2026')
    expect(document.querySelector('.prose strong')?.textContent).toBe('summary')
    expect(document.body.textContent).toContain('Added')
    expect(document.querySelector('li em')?.textContent).toBe('Shiny')
    expect(document.querySelector('[data-test="fallback"]')).toBeNull()
  })

  it('labels unreleased and yanked releases', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release({ version: 'Unreleased', date: null, unreleased: true }),
        release({ version: '1.0.0', yanked: true }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelectorAll('[data-test="release"]').length).toBe(2)
    expect(document.body.textContent).toContain('Unreleased')
    expect(document.querySelector('[data-test="yanked"]')?.textContent).toContain('Yanked')
  })

  it('shows the fallback notice only for non-English UI and fallback releases', async () => {
    i18n.global.setLocaleMessage('hu', (await import('../../../i18n/hu.json')).default)
    i18n.global.locale.value = 'hu'
    fakeApi({
      'GET /changelog': json(index([
        release({ fallback: true }),
        release({ version: '1.1.0' }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelectorAll('[data-test="release"]').length).toBe(2)
    const notices = document.querySelectorAll('[data-test="fallback"]')
    expect(notices).toHaveLength(1)
    expect(notices[0]?.textContent).toContain('Ez a verzió csak angolul érhető el.')
  })

  it('does not show the fallback notice in English', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release({ fallback: true }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.body.textContent).toContain('1.2.0')
    expect(document.querySelector('[data-test="fallback"]')).toBeNull()
  })

  it('appends the next page on load more', async () => {
    const server = fakeApi({
      'GET /changelog': [
        json(index([
          release(),
        ], 1, 2)),
        json(index([
          release({ version: '1.1.0' }),
        ], 2, 2)),
      ],
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelector('[data-test="load-more"]')).not.toBeNull()
    await click('[data-test="load-more"]')
    await expect.poll(() => document.body.textContent).toContain('1.1.0')
    expect(document.body.textContent).toContain('1.2.0')
    expect(document.querySelector('[data-test="load-more"]')).toBeNull()
    expect(server.of('GET /changelog')).toHaveLength(2)
  })

  it('shows the empty state', async () => {
    fakeApi({
      'GET /changelog': json(index([])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.body.textContent).toContain('There are no releases yet.')
  })

  it('shows a failure and retries it', async () => {
    const server = fakeApi({
      'GET /changelog': [
        problem(500, { title: 'Boom' }),
        json(index([
          release(),
        ])),
      ],
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelector('.v-alert')?.textContent).toContain('Boom')
    await click('[data-test="retry"]')
    await expect.poll(() => document.body.textContent).toContain('1.2.0')
    expect(document.querySelector('.v-alert')).toBeNull()
    expect(document.querySelector('[data-test="retry"]')).toBeNull()
    expect(server.of('GET /changelog')).toHaveLength(2)
  })

  it('refetches and replaces the list when the locale changes', async () => {
    i18n.global.setLocaleMessage('hu', (await import('../../../i18n/hu.json')).default)
    const server = fakeApi({
      'GET /changelog': [
        json(index([
          release(),
        ])),
        json(index([
          release({ version: '2.0.0' }),
        ])),
      ],
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.body.textContent).toContain('1.2.0')
    i18n.global.locale.value = 'hu'
    await expect.poll(() => document.body.textContent).toContain('2.0.0')
    expect(document.body.textContent).not.toContain('1.2.0')
    expect(server.of('GET /changelog')).toHaveLength(2)
  })
})
