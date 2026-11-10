import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '../../../i18n'
import { CHANGELOG_SEEN_KEY } from '../../../stores/changelog'
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

function group(name: string, ...texts: string[]) {
  return {
    name,
    summary_html: null,
    items: texts.map(text => ({ html: text, text })),
  }
}

function filters(): HTMLButtonElement[] {
  return [
    ...document.querySelectorAll<HTMLButtonElement>('[role="group"] button'),
  ]
}

function versions(): string[] {
  return [
    ...document.querySelectorAll('[data-test="release"]'),
  ].map(el => el.id)
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

  it('heads the page with the newest released version, skipping Unreleased', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release({ version: 'Unreleased', date: null, unreleased: true }),
        release(),
        release({ version: '1.1.0' }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelector('[data-test="current-version"]')?.textContent).toContain('Latest version: 1.2.0')
    expect(document.querySelector('h1')?.textContent).toContain('Changelog')
    expect(document.body.textContent).toContain('What\'s new')
    // The latest release is the open card with the pill; Unreleased sits above it as a row.
    expect(versions()).toEqual(['vUnreleased', 'v1.2.0', 'v1.1.0'])
    expect(document.querySelector('#v1\\.2\\.0 [data-test="latest-pill"]')?.textContent).toBe('Latest')
    expect(document.querySelector('#vUnreleased [data-test="release-toggle"]')?.textContent).toContain('Unreleased')
    expect(document.querySelectorAll('[data-test="latest-pill"]')).toHaveLength(1)
  })

  it('collapses older releases into rows with a plain summary and a change count', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release(),
        release({
          version: '1.1.0',
          groups: [
            group('Added', 'One', 'Two'),
            group('Fixed', 'Three'),
          ],
        }),
        release({
          version: '1.0.0',
          summary_html: null,
          groups: [
            group('Fixed', 'Only'),
          ],
        }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => versions()).toHaveLength(3)
    const row = document.querySelector<HTMLElement>('#v1\\.1\\.0')!
    const toggle = row.querySelector<HTMLButtonElement>('[data-test="release-toggle"]')!
    const body = row.querySelector<HTMLElement>('[data-test="release-body"]')!
    expect(toggle.closest('h2')).not.toBeNull()
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(toggle.getAttribute('aria-controls')).toBe(body.id)
    expect(body.style.display).toBe('none')
    expect(toggle.textContent).toContain('Big summary')
    expect(toggle.textContent).toContain('March 5, 2026')
    expect(row.querySelector('[data-test="change-count"]')?.textContent).toBe('3 changes')
    expect(document.querySelector('#v1\\.0\\.0 [data-test="change-count"]')?.textContent).toBe('1 change')
    // Without a summary the row names its areas.
    expect(document.querySelector('#v1\\.0\\.0 [data-test="release-toggle"]')?.textContent).toContain('Fixed')

    await click('#v1\\.1\\.0 [data-test="release-toggle"]')
    await expect.poll(() => toggle.getAttribute('aria-expanded')).toBe('true')
    expect(body.style.display).toBe('')
    expect([
      ...body.querySelectorAll('[data-test="group"] h3'),
    ].map(h => h.textContent?.trim())).toEqual(['Added', 'Fixed'])
    expect(body.textContent).toContain('Three')
  })

  it('pluralises the change count in Hungarian', async () => {
    i18n.global.setLocaleMessage('hu', (await import('../../../i18n/hu.json')).default)
    i18n.global.locale.value = 'hu'
    fakeApi({
      'GET /changelog': json(index([
        release(),
        release({
          version: '1.1.0',
          groups: [
            group('Added', 'One', 'Two'),
          ],
        }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelector('[data-test="change-count"]')?.textContent).toBe('2 változás')
    expect(document.querySelector('[data-test="current-version"]')?.textContent).toContain('Legújabb verzió: 1.2.0')
  })

  it('filters areas on the client', async () => {
    const server = fakeApi({
      'GET /changelog': json(index([
        release({
          groups: [
            group('Admin', 'A1'),
            group('API', 'P1'),
          ],
        }),
        release({
          version: '1.1.0',
          groups: [
            group('Status page', 'S1'),
          ],
        }),
        release({
          version: '1.0.0',
          groups: [
            group('API', 'P2'),
            group('Status page', 'S2'),
          ],
        }),
      ])),
    })
    mountComponent(ChangelogView)
    // Admin appears in one release only, so it is not offered as a filter.
    await expect.poll(() => filters().length).toBe(3)
    expect(document.querySelector('[role="group"]')?.getAttribute('aria-label')).toBe('Filter by area')
    expect(filters().map(b => b.textContent?.trim())).toEqual(['All', 'API', 'Status page'])
    expect(filters().map(b => b.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false'])

    filters()[1]!.click()
    await expect.poll(() => versions()).toEqual(['v1.2.0', 'v1.0.0'])
    expect(filters()[1]!.getAttribute('aria-pressed')).toBe('true')
    expect(filters()[0]!.getAttribute('aria-pressed')).toBe('false')
    expect(document.body.textContent).not.toContain('A1')
    expect(document.querySelector('#v1\\.0\\.0 [data-test="change-count"]')?.textContent).toBe('1 change')
    expect([
      ...document.querySelectorAll('[data-test="index-link"]'),
    ].map(a => a.getAttribute('href'))).toEqual(['#v1.2.0', '#v1.0.0'])

    filters()[0]!.click()
    await expect.poll(() => versions()).toHaveLength(3)
    expect(server.of('GET /changelog')).toHaveLength(1)
  })

  it('jumps from the version index and expands a collapsed release', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release(),
        release({ version: '1.1.0' }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelectorAll('[data-test="index-link"]').length).toBe(2)
    const links = [
      ...document.querySelectorAll<HTMLAnchorElement>('[data-test="index-link"]'),
    ]
    expect(links.map(a => a.getAttribute('href'))).toEqual(['#v1.2.0', '#v1.1.0'])
    expect(links.map(a => a.getAttribute('aria-current'))).toEqual(['true', null])

    links[1]!.click()
    await expect.poll(() => document.querySelector('#v1\\.1\\.0 [data-test="release-toggle"]')?.getAttribute('aria-expanded')).toBe('true')
    expect(links.map(a => a.getAttribute('aria-current'))).toEqual([null, 'true'])
    expect(document.activeElement?.id).toBe('v1.1.0')
  })

  it('gives an area the same tag colour wherever it appears', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release({
          groups: [
            group('Admin', 'A1'),
          ],
        }),
        release({
          version: '1.1.0',
          groups: [
            group('Admin', 'A2'),
          ],
        }),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => document.querySelectorAll('.cl-tag').length).toBe(2)
    const styles = [
      ...document.querySelectorAll<HTMLElement>('.cl-tag'),
    ].map(el => el.style.getPropertyValue('--cl-tag'))
    expect(styles[0]).toMatch(/^var\(--v-theme-(primary|success|maintenance|info|warning)\)$/)
    expect(styles[1]).toBe(styles[0])
  })

  it('remembers the newest release as seen', async () => {
    fakeApi({
      'GET /changelog': json(index([
        release({ version: 'Unreleased', date: null, unreleased: true }),
        release(),
      ])),
    })
    mountComponent(ChangelogView)
    await expect.poll(() => localStorage.getItem(CHANGELOG_SEEN_KEY)).toBe('1.2.0')
  })
})
