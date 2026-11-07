import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import fixture from '../../../components/notifications/__tests__/channel-types.fixture.json'
import { setEchoFactory } from '../../../live/useWorkspaceLive'
import { meWith, viewerCan } from '../../../test/fixtures'
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
    deliveries: [
      {
        id: 1,
        channel: { id: 3, name: 'Ops chat', type: 'slack' },
        status: 'failed',
        attempts: 3,
        last_error: 'timeout',
        sent_at: null,
      },
    ],
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

describe('notification channels', () => {
  beforeEach(() => {
    handlers.clear()
    setEchoFactory(() => echo as never)
  })

  it('secret field errors land on the secret input', async () => {
    fakeApi({
      ...baseRoutes(),
      'POST /workspaces/acme/notification-channels': problem(422, {
        title: 'The given data was invalid.',
        errors: {
          'secrets.bot_token': ['The bot token format is invalid.'],
          'config.mystery': ['Unmapped config problem.'],
        },
      }),
    })
    const { wrapper } = await mountApp('/w/acme/channels/new')
    await expect.poll(() => document.querySelector('[data-test="channel-type"]')).not.toBeNull()
    choose(wrapper, 'channel-type', 'telegram')
    await flushPromises()

    fill('input[name="channel_name"]', 'Pager')
    fill('input[name="config_chat_id"]', '-1001')
    fill('input[name="secrets_bot_token"]', 'broken')
    await submit('input[name="channel_name"]')

    await expect.poll(() => document.querySelector('input[name="secrets_bot_token"]')?.closest('.v-input')?.textContent)
      .toContain('The bot token format is invalid.')
    const alert = document.querySelector('.problem-alert')?.textContent ?? ''
    expect(alert).toContain('Unmapped config problem.')
    expect(alert).not.toContain('The bot token format is invalid.')
  })

  it('draft test send posts the unsaved form and shows the provider error', async () => {
    const server = fakeApi({
      ...baseRoutes(),
      'POST /workspaces/acme/notification-channels/test': json({
        data: { ok: false, error: 'invalid_token from Slack' },
      }),
    })
    const { wrapper } = await mountApp('/w/acme/channels/new')
    await expect.poll(() => document.querySelector('[data-test="channel-type"]')).not.toBeNull()
    choose(wrapper, 'channel-type', 'slack')
    await flushPromises()

    fill('input[name="channel_name"]', 'Ops chat')
    fill('input[name="secrets_webhook_url"]', 'https://hooks.example.com/T1')
    await click('[data-test="channel-test"]')

    await expect.poll(() => text()).toContain('invalid_token from Slack')
    const calls = server.of('POST /workspaces/acme/notification-channels/test')
    expect(calls).toHaveLength(1)
    expect(calls[0]?.body).toEqual({
      type: 'slack',
      name: 'Ops chat',
      active: true,
      is_default: false,
      config: {},
      secrets: { webhook_url: 'https://hooks.example.com/T1' },
    })
    expect(server.of('POST /workspaces/acme/notification-channels')).toHaveLength(0)
  })

  it('alert event refreshes the first history page', async () => {
    const queries: string[] = []
    const first = json(page([
      alertRow(1, 'Homepage went down'),
    ], 'next-page'))
    const second = json(page([
      alertRow(2, 'Checkout went down'),
      alertRow(1, 'Homepage went down'),
    ]))
    let n = 0
    const server = fakeApi({
      ...baseRoutes(),
      'GET /workspaces/acme/alerts': (req) => {
        queries.push(new URL(req.url).search)
        return n++ === 0
          ? first
          : second
      },
    })
    await mountApp('/w/acme/alerts')
    await expect.poll(() => text()).toContain('Homepage went down')
    await expect.poll(() => handlers.has('.alert.raised')).toBe(true)
    // Failed delivery is stated in words, not by colour alone.
    expect(text()).toContain('Failed')

    handlers.get('.alert.raised')!({
      alert_event_ids: [2],
    })
    await expect.poll(() => text()).toContain('Checkout went down')
    const gets = server.of('GET /workspaces/acme/alerts')
    expect(gets).toHaveLength(2)
    // The refetch starts at the first page, not at the cursor of the loaded one.
    expect(queries.some(q => q.includes('cursor'))).toBe(false)
  })

  it('edit patches only changed keys and keeps stored secrets untouched', async () => {
    const channel = {
      id: 3,
      name: 'Ops chat',
      type: 'webhook',
      config: {
        url: 'https://example.com/hook',
        method: 'POST',
        format: 'json',
        headers: [],
      },
      secret_keys: ['signing_secret'],
      templates: null,
      is_default: false,
      active: true,
      monitor_count: 2,
      last_success_at: null,
      last_failure_at: null,
      last_error: null,
      created_at: null,
      updated_at: null,
    }
    const server = fakeApi({
      ...baseRoutes(),
      'GET /workspaces/acme/notification-channels/3': json({ data: channel }),
      'PATCH /workspaces/acme/notification-channels/3': json({
        data: { ...channel, name: 'Renamed' },
      }),
    })
    await mountApp('/w/acme/channels/3')
    await expect.poll(() => document.querySelector('input[name="channel_name"]')).not.toBeNull()
    expect(document.querySelector('[data-test="secret-signing_secret-set"]')).not.toBeNull()
    expect(document.querySelector('input[name="secrets_signing_secret"]')).toBeNull()

    fill('input[name="channel_name"]', 'Renamed')
    await submit('input[name="channel_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/notification-channels/3')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/notification-channels/3')[0]?.body).toEqual({ name: 'Renamed' })
  })

  it('viewer cannot open the editor', async () => {
    fakeApi({
      ...baseRoutes(),
      'GET /me': json({
        data: meWith({ role: 'viewer', can: viewerCan }),
      }),
      'GET /workspaces/acme/notification-channels': json(page([])),
    })
    const { router } = await mountApp('/w/acme/channels/new')
    await expect.poll(() => router.currentRoute.value.name).toBe('ws.channels')
    expect(document.querySelector('[data-test="channel-save"]')).toBeNull()
    expect(document.querySelector('[data-test="channel-new"]')).toBeNull()
  })
})
