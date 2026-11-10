import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import en from '../../../i18n/en.json'
import { meWith } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'
import { destinationChanged } from '../../../utils/secrets'
import { choose, page } from '../../workspace/__tests__/helpers'

const REQUIRED = en.validation.required
const text = () => document.body.textContent ?? ''
const exists = (selector: string) => () => document.querySelector(selector)

const TYPE_INFO = [
  {
    type: 'http',
    default_interval_seconds: 60,
    min_interval_seconds: 20,
    supports_inverted: true,
    scheduled: true,
    defaults: {
      method: 'GET',
      headers: [],
      accepted_status: ['200-299'],
      max_redirects: 10,
      auth: { type: 'none', username: null },
    },
    destination_keys: ['url', 'proxy.url'],
  },
]

const MONITOR = {
  id: 1,
  name: 'Homepage',
  description: null,
  type: 'http',
  active: true,
  interval_seconds: 60,
  timeout_seconds: 10,
  retries: 1,
  retry_interval_seconds: 60,
  degraded_threshold_ms: null,
  inverted: false,
  parent_id: null,
  config: {
    url: 'https://example.com',
    method: 'GET',
    headers: {},
    accepted_status: ['200-299'],
    max_redirects: 10,
    header_checks: [],
    auth: { type: 'bearer', username: null },
    proxy: null,
  },
  secret_keys: ['bearer_token'],
  notification_channel_ids: [],
  alerting: {
    resend_interval_minutes: 0,
    notify_degraded: false,
    degraded_alert_after_seconds: 0,
    notify_changes: false,
  },
  tags: [],
  push_url: null,
  has_push_token: false,
  state: {
    status: 'up',
    since: '2025-12-31T00:00:00Z',
    last_checked_at: null,
    last_latency_ms: null,
    last_message: null,
  },
  created_at: null,
  updated_at: null,
}

function routes() {
  return {
    'GET /setup': json({ required: false }),
    'GET /me': json({
      data: meWith({}),
    }),
    'GET /monitor-types': json(TYPE_INFO),
    'GET /workspaces/acme/tags': json(page([])),
    'GET /workspaces/acme/notification-channels': json(page([])),
    'GET /workspaces/acme/monitors': json(page([])),
    'GET /workspaces/acme/monitors/1': json({ data: MONITOR }),
    'PATCH /workspaces/acme/monitors/1': json({ data: MONITOR }),
  }
}

const PATCH = 'PATCH /workspaces/acme/monitors/1'

/**
 * A save navigates to the monitor's page, whose view is loaded lazily; waiting for that navigation keeps
 * the import from outliving the test (Vitest reports it as an unhandled error otherwise).
 */
async function settled(router: { currentRoute: { value: { name?: unknown } } }): Promise<void> {
  await expect.poll(() => router.currentRoute.value.name).toBe('ws.monitor')
  await flushPromises()
}

describe('monitor editor: secrets follow the destination', () => {
  it('a new url needs the stored bearer token again before anything is sent', async () => {
    const server = fakeApi(routes())
    const { router } = await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="config_url"]')).not.toBeNull()
    expect(text()).not.toContain(`${en.monitorEditor.http.bearerToken} *`)

    fill('input[name="config_url"]', 'https://elsewhere.example.com')
    await flushPromises()
    expect(text()).toContain(`${en.monitorEditor.http.bearerToken} *`)
    await submit('input[name="monitor_name"]')
    await flushPromises()
    expect(server.of(PATCH)).toHaveLength(0)
    expect(text()).toContain(REQUIRED)

    await click('[data-test="secret-bearer_token-replace"]')
    await expect.poll(exists('input[name="secrets_bearer_token"]')).not.toBeNull()
    fill('input[name="secrets_bearer_token"]', 'tok-new')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of(PATCH)).toHaveLength(1)
    expect(server.of(PATCH)[0]?.body).toEqual({
      config: { url: 'https://elsewhere.example.com' },
      secrets: { bearer_token: 'tok-new' },
    })
    await settled(router)
  })

  it('another config field is saved without the secrets', async () => {
    const server = fakeApi(routes())
    const { router } = await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="config_max_redirects"]')).not.toBeNull()

    fill('input[name="config_max_redirects"]', '3')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of(PATCH)).toHaveLength(1)
    expect(server.of(PATCH)[0]?.body).toEqual({
      config: { max_redirects: 3 },
    })
    expect(text()).not.toContain(REQUIRED)
    await settled(router)
  })

  it('a stored secret whose control is switched off is removed with the new url', async () => {
    const server = fakeApi(routes())
    const { wrapper, router } = await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="config_url"]')).not.toBeNull()

    choose(wrapper, 'config-auth-type', 'none')
    await flushPromises()
    fill('input[name="config_url"]', 'https://elsewhere.example.com')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of(PATCH)).toHaveLength(1)
    expect(server.of(PATCH)[0]?.body).toEqual({
      config: {
        url: 'https://elsewhere.example.com',
        auth: { type: 'none', username: null },
      },
      secrets: { bearer_token: null },
    })
    await settled(router)
  })

  it('destination keys come from the type catalogue', () => {
    const keys = TYPE_INFO[0]!.destination_keys
    const base = { url: 'https://a.test', method: 'GET', proxy: null }
    expect(destinationChanged(keys, base, { ...base, method: 'HEAD' })).toBe(false)
    expect(destinationChanged(keys, base, { ...base, url: 'https://b.test' })).toBe(true)
    expect(destinationChanged(keys, base, {
      ...base,
      proxy: { url: 'http://p.test:3128', username: null },
    })).toBe(true)
  })
})
