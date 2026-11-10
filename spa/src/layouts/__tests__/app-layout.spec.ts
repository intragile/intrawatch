import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { CHANGELOG_SEEN_KEY } from '../../stores/changelog'
import { useSession } from '../../stores/session'
import { me, workspace } from '../../test/fixtures'
import { mountApp } from '../../test/mount'
import { fakeApi, json, problem } from '../../test/server'

const beta = workspace({ id: '01JBBBBBBBBBBBBBBBBBBBBBBB', slug: 'beta', name: 'Beta' })

function api(extra: Parameters<typeof fakeApi>[0] = {}) {
  return fakeApi({
    'GET /setup': json({ required: false }),
    'GET /me': json({
      data: me({
        workspaces: [
          workspace(),
          beta,
        ],
      }),
    }),
    'GET /manual': json({ data: [], locale: 'en', fallback: false }),
    'GET /changelog': json({
      data: [],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 20,
        total: 0,
      },
      locale: 'en',
      fallback: false,
    }),
    ...extra,
  })
}

function rail(): HTMLElement {
  const el = document.querySelector<HTMLElement>('nav.rail')
  if (!el)
    throw new Error('The rail is not rendered')
  return el
}

function railLinks() {
  return [
    ...rail().querySelectorAll('a'),
  ].map(a => a.getAttribute('href'))
}

describe('app layout navigation', () => {
  it('lists help and the changelog in the rail', async () => {
    api()
    await mountApp('/w/acme/monitors')
    await flushPromises()

    expect(railLinks()).toEqual(expect.arrayContaining(['/help', '/changelog']))
    expect(rail().querySelector('[data-test="nav-help"]')?.textContent).toContain('Help')
    expect(rail().querySelector('[data-test="nav-changelog"]')?.textContent).toContain('Changelog')
  })

  it('marks help active on a chapter page too', async () => {
    api({
      'GET /manual/chapter': json({
        data: {
          slug: 'monitoring/monitors',
          number: '2.1',
          title: 'Monitors',
          summary: 'What a monitor checks.',
          section: 'monitoring',
          position: 1,
          total: 1,
          readingMinutes: 1,
          html: '<p>Body.</p>',
          headings: [],
          prev: null,
          next: null,
          locale: 'en',
          fallback: false,
        },
      }),
    })
    const { router } = await mountApp('/help/monitoring/monitors')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('help.chapter')
    expect(rail().querySelector('[data-test="nav-help"]')?.classList).toContain('v-list-item--active')
    expect(rail().querySelector('[data-test="nav-changelog"]')?.classList).not.toContain('v-list-item--active')
  })

  it('keeps the last workspace in the rail on help and changelog pages', async () => {
    api()
    const { router } = await mountApp('/w/beta/monitors')
    await flushPromises()

    for (const path of ['/help', '/changelog', '/account']) {
      await router.push(path)
      await flushPromises()

      expect(useSession().current?.slug).toBe('beta')
      expect(railLinks()).toContain('/w/beta/monitors')
    }
  })

  it('falls back to the first workspace when none was used yet', async () => {
    localStorage.clear()
    api()
    await mountApp('/help')
    await flushPromises()

    expect(useSession().current?.slug).toBe('acme')
    expect(railLinks()).toContain('/w/acme/monitors')
  })
})

function release(version: string) {
  return {
    version,
    date: '2026-03-05',
    url: null,
    unreleased: false,
    yanked: false,
    summary_html: null,
    groups: [],
    locale: 'en',
    fallback: false,
  }
}

function badge(): Element | null {
  return rail().querySelector('[data-test="nav-changelog"] [data-test="changelog-new"]')
}

describe('app layout changelog badge', () => {
  it('marks a release that was never seen, with an accessible text', async () => {
    const server = api({
      'GET /changelog/latest': json({
        data: release('1.2.0'),
      }),
    })
    await mountApp('/w/acme/monitors')
    await flushPromises()

    await expect.poll(() => badge()).not.toBeNull()
    expect(badge()?.textContent).toContain('New')
    expect(badge()?.querySelector('.d-sr-only')?.textContent).toBe('new version available')
    expect(badge()?.querySelector('[aria-hidden="true"]')?.textContent).toBe('New')
    expect(server.of('GET /changelog/latest')).toHaveLength(1)
  })

  it('marks a release newer than the one seen', async () => {
    localStorage.setItem(CHANGELOG_SEEN_KEY, '1.1.0')
    api({
      'GET /changelog/latest': json({
        data: release('1.2.0'),
      }),
    })
    await mountApp('/w/acme/monitors')
    await flushPromises()

    await expect.poll(() => badge()).not.toBeNull()
  })

  it('does not mark a release already seen', async () => {
    localStorage.setItem(CHANGELOG_SEEN_KEY, '1.2.0')
    const server = api({
      'GET /changelog/latest': json({
        data: release('1.2.0'),
      }),
    })
    await mountApp('/w/acme/monitors')
    await flushPromises()

    await expect.poll(() => server.of('GET /changelog/latest')).toHaveLength(1)
    await flushPromises()
    expect(badge()).toBeNull()
  })

  it('stays quiet when there is no release or the request fails', async () => {
    const server = api({
      'GET /changelog/latest': problem(500, { title: 'Boom' }),
    })
    await mountApp('/w/acme/monitors')
    await flushPromises()

    await expect.poll(() => server.of('GET /changelog/latest')).toHaveLength(1)
    await flushPromises()
    expect(badge()).toBeNull()
    expect(rail().querySelector('[data-test="nav-help"]')).not.toBeNull()
    expect(document.querySelector('.v-snackbar')).toBeNull()
  })

  it('clears the mark once the changelog page was opened', async () => {
    api({
      'GET /changelog/latest': json({
        data: release('1.2.0'),
      }),
      'GET /changelog': json({
        data: [
          release('1.2.0'),
        ],
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 20,
          total: 1,
        },
        locale: 'en',
        fallback: false,
      }),
    })
    const { router } = await mountApp('/w/acme/monitors')
    await flushPromises()
    await expect.poll(() => badge()).not.toBeNull()

    await router.push('/changelog')
    await expect.poll(() => badge()).toBeNull()
    expect(localStorage.getItem(CHANGELOG_SEEN_KEY)).toBe('1.2.0')
  })
})
