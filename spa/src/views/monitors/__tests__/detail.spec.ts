import type { Monitor } from '../../../api/types'
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConfirmDialog from '../../../components/common/ConfirmDialog.vue'
import { setEchoFactory } from '../../../live/useWorkspaceLive'
import { meWith, monitor, viewerCan } from '../../../test/fixtures'
import { click, mountApp } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'
import { page } from '../../workspace/__tests__/helpers'

const charts = vi.hoisted(() => ({
  created: [] as Array<{ data: number[][], setData: number[][][] }>,
}))

vi.mock('uplot', () => ({
  default: class {
    record = { data: [] as number[][], setData: [] as number[][][] }
    constructor(_opts: unknown, data: number[][]) {
      this.record.data = data
      charts.created.push(this.record)
    }

    setData(data: number[][]): void {
      this.record.setData.push(data)
    }

    setSize(): void {}
    destroy(): void {}
  },
}))
vi.mock('uplot/dist/uPlot.min.css', () => ({}))

type Handler = (payload: unknown) => void
const handlers = new Map<string, Handler>()

const echo = {
  private: () => ({
    listen: (event: string, cb: Handler) => {
      handlers.set(event, cb)
      return {}
    },
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

const text = () => document.body.textContent ?? ''
const has = (selector: string) => document.querySelector(selector) !== null

function rollup(minutesAgo: number, over: Record<string, number | null> = {}) {
  return {
    bucket_start: new Date(Date.now() - minutesAgo * 60_000).toISOString(),
    total: 1,
    up: 1,
    down: 0,
    degraded: 0,
    pending: 0,
    maintenance: 0,
    availability: 1,
    latency_min: 10,
    latency_max: 30,
    latency_avg: 20,
    ...over,
  }
}

function detailApi(mon: Monitor, opts: { rollups?: (bucket: string) => unknown[], can?: ReturnType<typeof meWith>, extra?: Record<string, Response> } = {}) {
  const queries: URLSearchParams[] = []
  const server = fakeApi({
    'GET /setup': json({ required: false }),
    'GET /me': json({ data: opts.can ?? meWith({}) }),
    'GET /monitor-types': json([
      { type: 'http', scheduled: true },
    ]),
    'GET /workspaces/acme/monitors/1': json({ data: mon }),
    // The list pane renders beside the detail (master-detail).
    'GET /workspaces/acme/monitors': json(page([
      { ...mon, recent_beats: [] },
    ])),
    'GET /workspaces/acme/tags': json(page([])),
    'GET /workspaces/acme/monitors/1/results': json(page([])),
    'GET /workspaces/acme/monitors/1/events': json(page([])),
    'GET /workspaces/acme/monitors/1/alerts': json(page([])),
    'GET /workspaces/acme/monitors/1/rollups': (req) => {
      const params = new URL(req.url).searchParams
      queries.push(params)
      return json(page(opts.rollups?.(params.get('bucket') ?? '') ?? []))
    },
    ...opts.extra,
  })
  return { server, queries }
}

describe('monitor detail', () => {
  beforeEach(() => {
    charts.created.length = 0
    handlers.clear()
    setEchoFactory(() => echo as never)
  })

  it('range tabs request the documented bucket', async () => {
    const { queries, server } = detailApi(monitor())
    await mountApp('/w/acme/monitors/1')
    await expect.poll(() => queries.length).toBeGreaterThanOrEqual(4)

    const spans: Record<string, number[]> = {}
    for (const q of queries) {
      const from = Date.parse(q.get('from') ?? '')
      const to = Date.parse(q.get('to') ?? '')
      const days = Math.round((to - from) / 86_400_000)
      ;(spans[q.get('bucket') ?? ''] ??= []).push(days)
      expect(q.get('limit')).toBe('200')
    }
    expect(spans.minute).toEqual([1])
    expect(spans.hour).toEqual([7])
    expect((spans.day ?? []).sort((a, b) => a - b)).toEqual([30, 90])

    await click('[data-test="range-7d"]')
    await flushPromises()
    expect(document.querySelector('[data-test="chart-summary"]')?.textContent).toContain('7')
    expect(server.unexpected).toEqual([])
  })

  it('uptime formula excludes maintenance', async () => {
    detailApi(monitor(), {
      rollups: bucket => bucket === 'minute'
        ? [
            rollup(10, {
              total: 100,
              up: 60,
              degraded: 5,
              pending: 5,
              down: 10,
              maintenance: 20,
            }),
            rollup(9, {
              total: 50,
              up: 30,
              degraded: 0,
              pending: 0,
              down: 10,
              maintenance: 10,
            }),
          ]
        : [],
    })
    await mountApp('/w/acme/monitors/1')
    // (60+5+5+30) / (150-30) = 100/120
    await expect.poll(() => document.querySelector('[data-test="uptime-24h"]')?.textContent).toContain('83.33')
    expect(document.querySelector('[data-test="uptime-7d"]')?.textContent).not.toContain('%')
  })

  it('live check appends to the 24h chart data', async () => {
    detailApi(monitor(), {
      rollups: bucket => bucket === 'minute'
        ? [
            rollup(3),
            rollup(2),
          ]
        : [],
    })
    await mountApp('/w/acme/monitors/1')
    await expect.poll(() => charts.created.length).toBeGreaterThan(0)
    const chart = charts.created.at(-1)!
    const before = (chart.setData.at(-1) ?? chart.data)[0]!.length
    expect(before).toBe(2)

    const emit = handlers.get('.monitor.checks')!
    emit({
      checks: [
        {
          monitor_id: 2,
          status: 'up',
          latency_ms: 5,
          checked_at: new Date().toISOString(),
        },
      ],
    })
    await flushPromises()
    expect((chart.setData.at(-1) ?? chart.data)[0]!.length).toBe(2)

    emit({
      checks: [
        {
          monitor_id: 1,
          status: 'up',
          latency_ms: 42,
          checked_at: new Date().toISOString(),
        },
      ],
    })
    await expect.poll(() => (chart.setData.at(-1) ?? chart.data)[0]!.length).toBe(3)
    expect((chart.setData.at(-1) ?? chart.data)[1]!.at(-1)).toBe(42)
  })

  it('rotate push token asks and shows the new url', async () => {
    const mon = monitor({ type: 'push', has_push_token: true, push_url: 'https://iw.test/api/v1/push/OLDTOKEN' })
    const { server } = detailApi(mon, {
      extra: {
        'POST /workspaces/acme/monitors/1/push-token': json({ push_url: 'https://iw.test/api/v1/push/NEWTOKEN' }),
      },
    })
    const { wrapper } = await mountApp('/w/acme/monitors/1')
    await expect.poll(() => text()).toContain('OLDTOKEN')
    expect(text()).toContain('curl')

    await click('[data-test="rotate"]')
    await flushPromises()
    expect(server.of('POST /workspaces/acme/monitors/1/push-token')).toHaveLength(0)
    expect(wrapper.findAllComponents(ConfirmDialog).some(d => d.props('modelValue'))).toBe(true)

    await click('[data-test="confirm"]')
    await expect.poll(() => text()).toContain('NEWTOKEN')
    expect(text()).not.toContain('OLDTOKEN')
    expect(server.of('POST /workspaces/acme/monitors/1/push-token')).toHaveLength(1)
  })

  it('viewer sees no actions and no push url', async () => {
    const mon = monitor({ type: 'push', has_push_token: true, push_url: null })
    detailApi(mon, {
      can: meWith({ role: 'viewer', can: viewerCan }),
    })
    await mountApp('/w/acme/monitors/1')
    await expect.poll(() => text()).toContain('Homepage')
    await flushPromises()

    for (const action of [
      'pause',
      'resume',
      'check',
      'rotate',
      'edit',
    ])
      expect(has(`[data-test="${action}"]`), action).toBe(false)
    expect(text()).not.toContain('/push/')
    expect(text()).toContain('push token')
  })

  it('check now stays disabled until a checks event for the monitor arrives', async () => {
    const { server } = detailApi(monitor(), {
      extra: {
        'POST /workspaces/acme/monitors/1/check': json({
          check_requested_at: new Date().toISOString(),
        }, 202),
      },
    })
    await mountApp('/w/acme/monitors/1')
    await expect.poll(() => has('[data-test="check"]')).toBe(true)
    const button = () => document.querySelector<HTMLButtonElement>('[data-test="check"]')!
    expect(button().disabled).toBe(false)

    await click('[data-test="check"]')
    await expect.poll(() => button().disabled).toBe(true)
    expect(server.of('POST /workspaces/acme/monitors/1/check')).toHaveLength(1)

    handlers.get('.monitor.checks')!({
      checks: [
        {
          monitor_id: 1,
          status: 'up',
          latency_ms: 1,
          checked_at: new Date().toISOString(),
        },
      ],
    })
    await expect.poll(() => button().disabled).toBe(false)
  })
  it('leads with the status and how long it has lasted, and fills the strip from the results', async () => {
    const since = new Date(Date.now() - 32 * 60_000).toISOString()
    const mon = monitor({
      state: {
        ...monitor().state,
        status: 'down',
        since,
        last_message: 'HTTP 503',
      },
    })
    detailApi(mon, {
      extra: {
        'GET /workspaces/acme/monitors/1/results': json(page([
          {
            id: 2,
            status: 'down',
            latency_ms: null,
            message: 'HTTP 503',
            facts: null,
            checked_at: '2026-01-01T00:01:00+00:00',
          },
          {
            id: 1,
            status: 'up',
            latency_ms: 90,
            message: null,
            facts: null,
            checked_at: '2026-01-01T00:00:00+00:00',
          },
        ])),
        // Not in the list beside it, so the strip falls back to the results.
        'GET /workspaces/acme/monitors': json(page([])),
      },
    })
    await mountApp('/w/acme/monitors/1')
    await expect.poll(() => document.querySelector('[data-test="hero"]')?.textContent).toContain('Down')
    expect(document.querySelector('[data-test="hero"]')?.textContent).toContain('32 minutes')
    expect(text()).toContain('HTTP 503')
    await expect.poll(() => document.querySelector('.heartbeat--large')?.getAttribute('aria-label')).toContain('2')
    // The scale counts the beats shown, not the strip's capacity.
    expect(document.querySelector('.strip-scale')?.textContent).toContain('Last 2 checks')
  })
})
