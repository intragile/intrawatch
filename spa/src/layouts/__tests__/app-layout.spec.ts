import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { useSession } from '../../stores/session'
import { me, workspace } from '../../test/fixtures'
import { mountApp } from '../../test/mount'
import { fakeApi, json } from '../../test/server'

const beta = workspace({ id: '01JBBBBBBBBBBBBBBBBBBBBBBB', slug: 'beta', name: 'Beta' })

function api(extra: Parameters<typeof fakeApi>[0] = {}) {
  return fakeApi({
    ...extra,
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
