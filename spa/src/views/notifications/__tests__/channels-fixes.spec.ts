import type { ComponentPublicInstance } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import fixture from '../../../components/notifications/__tests__/channel-types.fixture.json'
import { useNotify } from '../../../composables/useNotify'
import { setEchoFactory } from '../../../live/useWorkspaceLive'
import { meWith, monitor } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import { choose, page } from '../../workspace/__tests__/helpers'

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

function channel(over: Record<string, unknown> = {}) {
  return {
    id: 3,
    name: 'Ops chat',
    type: 'slack',
    config: {},
    secret_keys: ['webhook_url'],
    templates: null,
    is_default: false,
    active: true,
    monitor_count: 2,
    last_success_at: null,
    last_failure_at: null,
    last_error: null,
    created_at: null,
    updated_at: null,
    ...over,
  }
}

function baseRoutes() {
  return {
    'GET /setup': json({ required: false }),
    'GET /me': json({
      data: meWith({}),
    }),
    'GET /notification-channel-types': json(fixture),
  }
}

function alertRow(id: number, message: string) {
  return {
    id,
    kind: 'down',
    monitor: { id: 1, name: 'Homepage' },
    status: 'down',
    previous_status: 'up',
    message,
    duration_seconds: 90,
    changes: null,
    suppressed_by_monitor_id: null,
    created_at: '2026-01-01T10:00:00+00:00',
    deliveries: [],
  }
}

describe('channel fix round', () => {
  beforeEach(() => {
    useNotify().notices.value = []
    handlers.clear()
    setEchoFactory(() => echo as never)
  })

  it('since filter is sent without milliseconds', async () => {
    const queries: URLSearchParams[] = []
    fakeApi({
      ...baseRoutes(),
      'GET /workspaces/acme/alerts': (req) => {
        queries.push(new URL(req.url).searchParams)
        return json(page([]))
      },
    })
    await mountApp('/w/acme/alerts')
    await expect.poll(() => queries.length).toBe(1)
    fill('input[name="alert_since"]', '2026-01-02T10:30')
    await expect.poll(() => queries.length).toBe(2)
    const expected = new Date('2026-01-02T10:30').toISOString().replace(/\.\d{3}Z$/, 'Z')
    expect(queries[1]?.get('since')).toBe(expected)
    expect(expected).not.toContain('.')
  })

  it('leave guard works again after a successful create', async () => {
    const server = fakeApi({
      ...baseRoutes(),
      'POST /workspaces/acme/notification-channels': json({
        data: channel({ id: 9 }),
      }, 201),
      'GET /workspaces/acme/notification-channels/9': json({
        data: channel({ id: 9 }),
      }),
      'GET /workspaces/acme/notification-channels': json(page([])),
    })
    const { wrapper, router } = await mountApp('/w/acme/channels/new')
    await expect.poll(() => document.querySelector('[data-test="channel-type"]')).not.toBeNull()
    choose(wrapper, 'channel-type', 'slack')
    await flushPromises()
    fill('input[name="channel_name"]', 'Ops chat')
    fill('input[name="secrets_webhook_url"]', 'https://hooks.example.com/T1')
    await submit('input[name="channel_name"]')
    await expect.poll(() => router.currentRoute.value.name).toBe('ws.channel')
    await expect.poll(() => server.of('GET /workspaces/acme/notification-channels/9')).toHaveLength(1)
    await expect.poll(() => (document.querySelector('input[name="channel_name"]') as HTMLInputElement | null)?.value).toBe('Ops chat')
    await flushPromises()

    fill('input[name="channel_name"]', 'Edited again')
    void router.push({
      name: 'ws.channels',
      params: { workspace: 'acme' },
    })
    await expect.poll(() => document.querySelector('[data-test="confirm"]')).not.toBeNull()
    expect(router.currentRoute.value.name).toBe('ws.channel')
  })

  it('live reload merges rows by id and keeps loaded older pages', async () => {
    let live = false
    fakeApi({
      ...baseRoutes(),
      'GET /workspaces/acme/alerts': (req) => {
        if (new URL(req.url).searchParams.get('cursor') === 'n1') {
          return json(page([
            alertRow(0, 'oldest'),
          ]))
        }
        return live
          ? json(page([
              alertRow(2, 'second'),
              alertRow(1, 'first'),
            ], 'n1'))
          : json(page([
              alertRow(1, 'first'),
            ], 'n1'))
      },
    })
    await mountApp('/w/acme/alerts')
    await expect.poll(() => text()).toContain('first')
    await expect.poll(() => handlers.has('.alert.raised')).toBe(true)
    await click('[data-test="alerts-more"]')
    await expect.poll(() => text()).toContain('oldest')
    live = true
    handlers.get('.alert.raised')!({
      alert_event_ids: [2],
    })
    await expect.poll(() => text()).toContain('second')
    for (const id of [0, 1, 2])
      expect(document.querySelectorAll(`[data-test="alert-row-${id}"]`)).toHaveLength(1)
  })

  it('switching smtp off clears the stored smtp password', async () => {
    const mail = channel({
      type: 'email',
      config: {
        to: ['a@example.com'],
        smtp: {
          host: 'mx',
          port: 587,
          encryption: 'starttls',
          username: null,
          from_address: 'x@example.com',
          from_name: null,
        },
      },
      secret_keys: ['smtp_password'],
    })
    const server = fakeApi({
      ...baseRoutes(),
      'GET /workspaces/acme/notification-channels/3': json({ data: mail }),
      'PATCH /workspaces/acme/notification-channels/3': json({
        data: {
          ...mail,
          config: {
            to: ['a@example.com'],
            smtp: null,
          },
          secret_keys: [],
        },
      }),
    })
    await mountApp('/w/acme/channels/3')
    await expect.poll(() => document.querySelector('[data-test="smtp-toggle"]')).not.toBeNull()
    await click('[data-test="smtp-toggle"] input')
    await submit('input[name="channel_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/notification-channels/3')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/notification-channels/3')[0]?.body).toEqual({
      config: { smtp: null },
      secrets: { smtp_password: null },
    })
  })

  it('bind success shows only the success notice and marks subscribed monitors', async () => {
    const server = fakeApi({
      ...baseRoutes(),
      'GET /workspaces/acme/notification-channels/3': [
        json({
          data: channel(),
        }),
        problem(500, { title: 'Refresh exploded' }),
      ],
      'GET /workspaces/acme/monitors': json(page([
        monitor({
          id: 5,
          name: 'Homepage',
          notification_channel_ids: [3],
        }),
        monitor({ id: 6, name: 'Checkout', notification_channel_ids: [] }),
      ])),
      'POST /workspaces/acme/monitors/bulk': json({ affected: 1 }),
    })
    const { wrapper } = await mountApp('/w/acme/channels/3')
    await expect.poll(() => document.querySelector('[data-test="binding-monitors"]')).not.toBeNull()
    const picker = wrapper.findComponent<ComponentPublicInstance>('[data-test="binding-monitors"]')
    picker.vm.$emit('focus')
    await expect.poll(() => ((picker.vm.$props as { items: Array<{ name: string }> }).items).map(i => i.name))
      .toEqual(['Homepage (subscribed)', 'Checkout'])

    picker.vm.$emit('update:modelValue', [6])
    await flushPromises()
    await click('[data-test="binding-notify"]')
    await expect.poll(() => server.of('POST /workspaces/acme/monitors/bulk')).toHaveLength(1)
    const { notices } = useNotify()
    await expect.poll(() => notices.value.map(n => n.text)).toContain('1 monitors subscribed.')
    await flushPromises()
    expect(notices.value.filter(n => n.color === 'error')).toEqual([])
    expect(text()).not.toContain('Refresh exploded')
  })

  it('apprise explains that a config key or urls are needed', async () => {
    fakeApi(baseRoutes())
    const { wrapper } = await mountApp('/w/acme/channels/new')
    await expect.poll(() => document.querySelector('[data-test="channel-type"]')).not.toBeNull()
    choose(wrapper, 'channel-type', 'apprise')
    await expect.poll(() => text()).toContain('Needed unless you give')
  })
})
