import type { Monitor } from '../../../api/types'
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import ConfirmDialog from '../../../components/common/ConfirmDialog.vue'
import { setEchoFactory } from '../../../live/useWorkspaceLive'
import { useMonitors } from '../../../stores/monitors'
import { meWith, monitor, viewerCan } from '../../../test/fixtures'
import { click, fill, mountApp } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import { choose, page } from '../../workspace/__tests__/helpers'

const idleEcho = {
  private: () => ({
    listen: () => ({}),
    error: () => ({}),
  }),
  leave: () => {},
  disconnect: () => {},
  connector: {
    pusher: {
      connection: {
        state: 'connected',
        bind: () => {},
        unbind: () => {},
      },
    },
  },
}

type Reply = (req: Request) => Response

/** Answers the monitor list by `parent_id` and records the query of every request. */
function monitorsApi(rows: Record<string, Monitor[]>, extra: Record<string, Response | Reply | Array<Response | Reply>> = {}, can?: ReturnType<typeof meWith>) {
  const queries: URLSearchParams[] = []
  const server = fakeApi({
    'GET /setup': json({ required: false }),
    'GET /me': json({ data: can ?? meWith({}) }),
    'GET /workspaces/acme/tags': json(page([
      { id: 3, name: 'prod', color: '#00ff00' },
    ])),
    'GET /workspaces/acme/notification-channels': json(page([])),
    'GET /workspaces/acme/monitors': (req) => {
      const params = new URL(req.url).searchParams
      queries.push(params)
      return json(page(rows[params.get('parent_id') ?? 'all'] ?? rows.all ?? []))
    },
    ...extra,
  })
  return { server, queries }
}

const text = () => document.body.textContent ?? ''

describe('monitor list', () => {
  beforeEach(() => {
    setEchoFactory(() => idleEcho as never)
  })

  it('list requests parent_id=null with limit 100 and never fetches per row', async () => {
    const rows = Array.from({ length: 30 }, (_, i) => monitor({ id: i + 1, name: `Site ${i + 1}` }))
    const { server, queries } = monitorsApi({ null: rows })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('Site 30')

    expect(queries).toHaveLength(1)
    expect(queries[0]?.get('parent_id')).toBe('null')
    expect(queries[0]?.get('limit')).toBe('100')
    const paths = new Set(server.calls.map(c => c.path))
    expect([...paths].filter(p => p.startsWith('/workspaces/acme/monitors/'))).toEqual([])
    expect(server.unexpected).toEqual([])
  })

  it('filters are reflected in the URL and switch to flat mode', async () => {
    const { queries } = monitorsApi({
      null: [
        monitor({ id: 1, name: 'Top' }),
      ],
      all: [
        monitor({ id: 9, name: 'Nested child', parent_id: 1 }),
      ],
    })
    const { wrapper, router } = await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('Top')

    choose(wrapper, 'filter-type', 'http')
    await expect.poll(() => router.currentRoute.value.query.type).toBe('http')
    await expect.poll(() => text()).toContain('Nested child')
    const flat = queries.at(-1)
    expect(flat?.get('type')).toBe('http')
    expect(flat?.has('parent_id')).toBe(false)

    await flushPromises()
    fill('input[name="q"]', 'nest')
    await expect.poll(() => router.currentRoute.value.query.q).toBe('nest')
    expect(queries.at(-1)?.get('q')).toBe('nest')
  })

  it('expanding a group fetches its children once', async () => {
    const { queries } = monitorsApi({
      null: [
        monitor({ id: 5, name: 'Cluster', type: 'group' }),
      ],
      5: [
        monitor({ id: 6, name: 'Child A', parent_id: 5 }),
      ],
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => document.querySelector('[data-test="expand-5"]')).not.toBeNull()

    await click('[data-test="expand-5"]')
    await expect.poll(() => text()).toContain('Child A')
    await click('[data-test="expand-5"]')
    await expect.poll(() => text()).not.toContain('Child A')
    await click('[data-test="expand-5"]')
    await expect.poll(() => text()).toContain('Child A')

    const childRequests = queries.filter(q => q.get('parent_id') === '5')
    expect(childRequests).toHaveLength(1)
    expect(childRequests[0]?.get('limit')).toBe('100')
  })

  it('bulk pause posts selected ids and reloads loaded pages', async () => {
    const { server } = monitorsApi({
      null: [
        monitor({ id: 1, name: 'One' }),
        monitor({ id: 2, name: 'Two' }),
      ],
    }, {
      'POST /workspaces/acme/monitors/bulk': json({ affected: 2 }),
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('Two')

    await click('[data-test="select-mode"]')
    await click('[data-test="select-1"]')
    await click('[data-test="select-2"]')
    await click('[data-test="bulk-pause"]')

    await expect.poll(() => server.of('POST /workspaces/acme/monitors/bulk')).toHaveLength(1)
    expect(server.of('POST /workspaces/acme/monitors/bulk')[0]?.body).toEqual({
      action: 'pause',
      ids: [1, 2],
    })
    await expect.poll(() => server.of('GET /workspaces/acme/monitors')).toHaveLength(2)
    await expect.poll(() => document.querySelector('[data-test="bulk-bar"]')).toBeNull()
  })

  it('bulk over 500 is blocked with a message', async () => {
    const rows = Array.from({ length: 501 }, (_, i) => monitor({ id: i + 1, name: `M${i + 1}` }))
    const { server } = monitorsApi({ null: rows })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('M501')

    await click('[data-test="select-mode"]')
    await click('[data-test="select-all"]')
    await expect.poll(() => document.querySelector('[data-test="bulk-limit"]')).not.toBeNull()
    expect(document.querySelector<HTMLButtonElement>('[data-test="bulk-pause"]')?.disabled).toBe(true)
    await click('[data-test="bulk-pause"]')
    expect(server.of('POST /workspaces/acme/monitors/bulk')).toHaveLength(0)
  })

  it('viewer sees no bulk bar', async () => {
    monitorsApi({
      null: [
        monitor({ id: 1, name: 'One' }),
      ],
    }, {}, meWith({ role: 'viewer', can: viewerCan }))
    await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('One')

    expect(document.querySelector('[data-test="select-1"]')).toBeNull()
    expect(document.querySelector('[data-test="select-all"]')).toBeNull()
    expect(document.querySelector('[data-test="bulk-bar"]')).toBeNull()
    expect(document.querySelector('[data-test="new-monitor"]')).toBeNull()
  })

  it('status change event updates the row without refetch', async () => {
    const { server } = monitorsApi({
      null: [
        monitor({ id: 1, name: 'One' }),
      ],
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => document.querySelector('[data-test="row-1"]')?.textContent).toContain('Up')

    useMonitors().applyStatus({
      monitor_id: 1,
      from: 'up',
      to: 'down',
      at: '2026-01-01T00:00:00Z',
      previous_since: null,
      message: 'timeout',
    })
    await expect.poll(() => document.querySelector('[data-test="row-1"]')?.textContent).toContain('Down')
    expect(server.of('GET /workspaces/acme/monitors')).toHaveLength(1)
  })

  it('window focus reloads the loaded pages', async () => {
    const { server } = monitorsApi({
      null: [
        monitor({ id: 1, name: 'One' }),
      ],
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('One')

    window.dispatchEvent(new Event('focus'))
    await expect.poll(() => server.of('GET /workspaces/acme/monitors')).toHaveLength(2)
  })

  it('collapsing a group deselects its children so bulk never touches hidden rows', async () => {
    const { server } = monitorsApi({
      null: [
        monitor({ id: 5, name: 'Cluster', type: 'group' }),
        monitor({ id: 1, name: 'Solo' }),
      ],
      5: [
        monitor({ id: 6, name: 'Child A', parent_id: 5 }),
      ],
    }, {
      'POST /workspaces/acme/monitors/bulk': json({ affected: 1 }),
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => document.querySelector('[data-test="expand-5"]')).not.toBeNull()
    await click('[data-test="select-mode"]')
    await click('[data-test="expand-5"]')
    await expect.poll(() => document.querySelector('[data-test="select-6"]')).not.toBeNull()

    await click('[data-test="select-6"]')
    await click('[data-test="select-1"]')
    await click('[data-test="expand-5"]')
    await expect.poll(() => document.querySelector('[data-test="select-6"]')).toBeNull()
    await click('[data-test="bulk-pause"]')

    await expect.poll(() => server.of('POST /workspaces/acme/monitors/bulk')).toHaveLength(1)
    expect(server.of('POST /workspaces/acme/monitors/bulk')[0]?.body).toEqual({
      action: 'pause',
      ids: [1],
    })
  })

  it('a failed child fetch shows an error row and retries', async () => {
    let calls = 0
    monitorsApi({
      null: [
        monitor({ id: 5, name: 'Cluster', type: 'group' }),
      ],
    }, {})
    // Replace the monitors route: children fail once.
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/tags': json(page([])),
      'GET /workspaces/acme/monitors': (req) => {
        const parent = new URL(req.url).searchParams.get('parent_id')
        if (parent === '5') {
          calls++
          return calls === 1
            ? problem(500, { title: 'Boom' })
            : json(page([
                monitor({ id: 6, name: 'Child A', parent_id: 5 }),
              ]))
        }
        return json(page([
          monitor({ id: 5, name: 'Cluster', type: 'group' }),
        ]))
      },
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => document.querySelector('[data-test="expand-5"]')).not.toBeNull()
    await click('[data-test="expand-5"]')
    await expect.poll(() => document.querySelector('[data-test="failed-5"]')?.textContent).toContain('Boom')

    await click('[data-test="retry-5"]')
    await expect.poll(() => text()).toContain('Child A')
  })

  it('a failed bulk delete closes the dialog and shows the problem', async () => {
    monitorsApi({
      null: [
        monitor({ id: 1, name: 'One' }),
      ],
    }, {
      'POST /workspaces/acme/monitors/bulk': problem(403, { title: 'Forbidden here' }),
    })
    const { wrapper } = await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('One')
    await click('[data-test="select-mode"]')
    await click('[data-test="select-1"]')
    await click('[data-test="bulk-delete"]')
    await click('[data-test="confirm"]')

    await expect.poll(() => text()).toContain('Forbidden here')
    await expect.poll(() => wrapper.findComponent(ConfirmDialog).props('modelValue')).toBe(false)
  })

  it('search keeps a trailing space while typing', async () => {
    monitorsApi({
      null: [
        monitor({ id: 1, name: 'One' }),
      ],
    })
    const { router } = await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('One')
    fill('input[name="q"]', 'a ')
    await expect.poll(() => router.currentRoute.value.query.q).toBe('a')
    await flushPromises()
    expect(document.querySelector<HTMLInputElement>('input[name="q"]')?.value).toBe('a ')
  })
  it('shows checkboxes only in selection mode and clears the selection when it ends', async () => {
    monitorsApi({
      null: [
        monitor({ id: 1, name: 'One' }),
      ],
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => text()).toContain('One')
    expect(document.querySelector('[data-test="select-1"]')).toBeNull()

    await click('[data-test="select-mode"]')
    await click('[data-test="select-1"]')
    await expect.poll(() => document.querySelector('[data-test="bulk-bar"]')).not.toBeNull()

    await click('[data-test="select-mode"]')
    await expect.poll(() => document.querySelector('[data-test="select-1"]')).toBeNull()
    expect(document.querySelector('[data-test="bulk-bar"]')).toBeNull()
  })

  it('status chips count the loaded monitors and filter by status', async () => {
    const { queries } = monitorsApi({
      null: [
        monitor({
          id: 1,
          name: 'One',
          state: {
            ...monitor().state,
            status: 'down',
          },
        }),
        monitor({ id: 2, name: 'Two' }),
      ],
      all: [
        monitor({
          id: 1,
          name: 'One',
          state: {
            ...monitor().state,
            status: 'down',
          },
        }),
      ],
    })
    const { router } = await mountApp('/w/acme/monitors')
    await expect.poll(() => document.querySelector('[data-test="summary-down"]')?.textContent).toContain('1')
    expect(document.querySelector('[data-test="status-all"]')?.textContent).toContain('2')

    await click('[data-test="summary-down"]')
    await expect.poll(() => router.currentRoute.value.query.status).toBe('down')
    expect(queries.at(-1)?.get('status')).toBe('down')
    expect(queries.at(-1)?.get('beats')).toBe('50')
  })

  it('fills the strips from the beats in the list response', async () => {
    monitorsApi({
      null: [
        {
          ...monitor({ id: 1, name: 'One' }),
          recent_beats: [
            { status: 'up', latency_ms: 20, checked_at: '2026-01-01T00:00:00+00:00' },
            { status: 'down', latency_ms: null, checked_at: '2026-01-01T00:01:00+00:00' },
          ],
        } as Monitor,
      ],
    })
    await mountApp('/w/acme/monitors')
    await expect.poll(() => useMonitors().beats.get(1)?.length).toBe(2)
    await expect.poll(() => document.querySelector('[data-test="row-1"] [role="img"]')?.getAttribute('aria-label')).toContain('2')
  })
})
