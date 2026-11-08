import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import spec from '../../../../../../docs/openapi.json'
import { meWith } from '../../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../../test/mount'
import { fakeApi, json, problem } from '../../../../test/server'
import { choose, page } from '../../../../views/workspace/__tests__/helpers'
import { FORM_KEYS, FORMS } from '../registry'

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
  {
    type: 'tcp',
    default_interval_seconds: 60,
    min_interval_seconds: 20,
    supports_inverted: true,
    scheduled: true,
    defaults: {},
  },
  {
    type: 'tls',
    default_interval_seconds: 3600,
    min_interval_seconds: 20,
    supports_inverted: false,
    scheduled: true,
    defaults: {
      port: 443,
      starttls: 'none',
      expiry_days: [30, 14, 7, 1],
      degraded_below_days: 7,
    },
  },
  {
    type: 'push',
    default_interval_seconds: 60,
    min_interval_seconds: 20,
    supports_inverted: false,
    scheduled: false,
    defaults: { grace_seconds: 30 },
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
    headers: { 'X-Trace': '1' },
    accepted_status: ['200-299'],
    max_redirects: 10,
    header_checks: [
      { name: 'X-Check', operator: 'equals', value: 'a' },
    ],
    auth: { type: 'bearer', username: null },
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

function routes(extra: Record<string, Parameters<typeof fakeApi>[0][string]> = {}) {
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
    ...extra,
  }
}

const exists = (selector: string) => () => document.querySelector(selector)
const value = (selector: string) => document.querySelector<HTMLInputElement>(selector)?.value
const text = () => document.body.textContent ?? ''

function messagesOf(inputSelector: string): string {
  return document.querySelector(inputSelector)?.closest('.v-input')?.textContent ?? ''
}

describe('monitor editor', () => {
  it('every MonitorType has a form', () => {
    const types = spec.components.schemas.MonitorType.enum
    expect(types.length).toBeGreaterThan(0)
    for (const type of types) {
      expect(FORMS, type).toHaveProperty(type)
      expect(FORM_KEYS, type).toHaveProperty(type)
    }
    expect(Object.keys(FORMS).sort()).toEqual([...types].sort())
  })

  it('create prefills defaults from monitor-types', async () => {
    fakeApi(routes())
    const { wrapper } = await mountApp('/w/acme/monitors/new')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    expect(value('input[name="interval_seconds"]')).toBe('60')
    expect(document.querySelector('input[name="config_url"]')).not.toBeNull()

    choose(wrapper, 'monitor-type', 'tls')
    await flushPromises()
    expect(value('input[name="interval_seconds"]')).toBe('3600')
    expect(value('input[name="config_port"]')).toBe('443')
    // TLS monitors cannot be inverted, so the switch is not offered.
    expect(document.querySelector('[data-test="inverted"]')).toBeNull()
  })

  it('nested config errors land on their fields', async () => {
    const server = fakeApi(routes({
      'PATCH /workspaces/acme/monitors/1': json({
        type: 'about:blank',
        title: 'The given data was invalid.',
        status: 422,
        errors: {
          'config.header_checks.0.value': ['Check value is too long.'],
          'config.headers.X-Trace': ['Header value is too long.'],
          'config.accepted_status.0': ['Bad status range.'],
          'secrets.bearer_token': ['Bearer token is too long.'],
        },
      }, 422),
    }))
    await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    fill('input[name="monitor_name"]', 'Renamed')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(1)
    await flushPromises()

    expect(messagesOf('input[name="config_header_checks_0_value"]')).toContain('Check value is too long.')
    expect(messagesOf('input[name="config_headers_0_value"]')).toContain('Header value is too long.')
    expect(messagesOf('input[name="config_accepted_status"]')).toContain('Bad status range.')
    expect(text()).toContain('Bearer token is too long.')
    // Everything is shown inline, so there is no form-level alert.
    expect(document.querySelector('.problem-alert')).toBeNull()
    // A failed save keeps what was typed.
    expect(value('input[name="monitor_name"]')).toBe('Renamed')
  })

  it('unknown error keys show in the form alert', async () => {
    const server = fakeApi(routes({
      'PATCH /workspaces/acme/monitors/1': json({
        type: 'about:blank',
        title: 'The given data was invalid.',
        status: 422,
        errors: {
          'name': ['The name is taken.'],
          'config.bogus': ['Bogus is not allowed.'],
          'secrets.nope': ['Nope is not a secret.'],
        },
      }, 422),
    }))
    await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    fill('input[name="monitor_name"]', 'Taken')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(1)
    await flushPromises()

    const alert = document.querySelector('.problem-alert')?.textContent ?? ''
    expect(alert).toContain('Bogus is not allowed.')
    expect(alert).toContain('Nope is not a secret.')
    expect(alert).not.toContain('The name is taken.')
    expect(messagesOf('input[name="monitor_name"]')).toContain('The name is taken.')
  })

  it('unchanged secrets are not sent; replaced secret is', async () => {
    const server = fakeApi(routes({
      'PATCH /workspaces/acme/monitors/1': json({ data: MONITOR }),
    }))
    const { router } = await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    // A stored secret shows as set and offers no input until replaced.
    expect(document.querySelector('input[name="secrets_bearer_token"]')).toBeNull()
    expect(document.querySelector('[data-test="secret-bearer_token-replace"]')).not.toBeNull()

    fill('input[name="monitor_name"]', 'First')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/monitors/1')[0]?.body).toEqual({ name: 'First' })

    await expect.poll(() => router.currentRoute.value.name).toBe('ws.monitor')
    await router.push('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    await click('[data-test="secret-bearer_token-replace"]')
    await expect.poll(exists('input[name="secrets_bearer_token"]')).not.toBeNull()
    fill('input[name="secrets_bearer_token"]', 'new-token')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(2)
    expect(server.of('PATCH /workspaces/acme/monitors/1')[1]?.body).toEqual({
      secrets: { bearer_token: 'new-token' },
    })
  })

  it('patch sends only changed keys', async () => {
    const server = fakeApi(routes({
      // Without stored secrets, so the url change needs no secret re-entry (see edit-destination.spec.ts).
      'GET /workspaces/acme/monitors/1': json({
        data: { ...MONITOR, secret_keys: [] },
      }),
      'PATCH /workspaces/acme/monitors/1': json({
        data: { ...MONITOR, interval_seconds: 120 },
      }),
    }))
    await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="interval_seconds"]')).not.toBeNull()
    fill('input[name="interval_seconds"]', '120')
    fill('input[name="config_url"]', 'https://example.org')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/monitors/1')[0]?.body).toEqual({
      interval_seconds: 120,
      config: { url: 'https://example.org' },
    })
  })

  it('create posts the type with defaults and routes to the new monitor', async () => {
    const server = fakeApi(routes({
      'POST /workspaces/acme/monitors': json({
        data: { ...MONITOR, id: 7 },
      }, 201),
    }))
    const { router } = await mountApp('/w/acme/monitors/new')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    fill('input[name="monitor_name"]', 'API')
    fill('input[name="config_url"]', 'https://api.example.com')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('POST /workspaces/acme/monitors')).toHaveLength(1)
    const body = server.of('POST /workspaces/acme/monitors')[0]?.body as Record<string, unknown>
    expect(body).toMatchObject({ type: 'http', name: 'API', interval_seconds: 60 })
    expect(body.config).toMatchObject({ url: 'https://api.example.com', method: 'GET' })
    expect(body).not.toHaveProperty('secrets')
    await expect.poll(() => router.currentRoute.value.name).toBe('ws.monitor')
    expect(router.currentRoute.value.params.monitor).toBe('7')
  })

  it('a rejected save keeps the form and a later save succeeds', async () => {
    const server = fakeApi(routes({
      'PATCH /workspaces/acme/monitors/1': [
        problem(500, { title: 'Boom' }),
        json({ data: MONITOR }),
      ],
    }))
    await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    fill('input[name="monitor_name"]', 'Kept')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => text()).toContain('Boom')
    expect(value('input[name="monitor_name"]')).toBe('Kept')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(2)
    expect(server.of('PATCH /workspaces/acme/monitors/1')[1]?.body).toEqual({ name: 'Kept' })
  })

  it('leaving with unsaved changes asks for confirmation', async () => {
    fakeApi(routes())
    const { router } = await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()

    // Nothing changed yet: leaving is free.
    fill('input[name="monitor_name"]', 'Dirty')
    const leaving = router.push('/w/acme/monitors')
    await expect.poll(exists('[data-test="confirm"]')).not.toBeNull()
    expect(router.currentRoute.value.name).toBe('ws.monitor.edit')

    // Cancelling stays on the form with the edits intact.
    const cancel = [
      ...document.querySelectorAll<HTMLButtonElement>('.v-dialog button'),
    ].find(b => b.textContent?.trim() === 'Cancel')
    cancel?.click()
    await leaving
    expect(router.currentRoute.value.name).toBe('ws.monitor.edit')
    expect(value('input[name="monitor_name"]')).toBe('Dirty')

    const again = router.push('/w/acme/monitors')
    await click('[data-test="confirm"]')
    await again
    expect(router.currentRoute.value.name).toBe('ws.monitors')
  })

  it('leaving a pristine form does not ask', async () => {
    fakeApi(routes())
    const { router } = await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    await router.push('/w/acme/monitors')
    expect(router.currentRoute.value.name).toBe('ws.monitors')
  })

  it('a non-scheduled type shows a timing-field error in the form alert', async () => {
    const server = fakeApi(routes({
      'POST /workspaces/acme/monitors': json({
        type: 'about:blank',
        title: 'The given data was invalid.',
        status: 422,
        errors: {
          timeout_seconds: ['The timeout is too long.'],
        },
      }, 422),
    }))
    const { wrapper } = await mountApp('/w/acme/monitors/new')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    choose(wrapper, 'monitor-type', 'push')
    await flushPromises()
    expect(document.querySelector('input[name="timeout_seconds"]')).toBeNull()
    fill('input[name="monitor_name"]', 'Job')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('POST /workspaces/acme/monitors')).toHaveLength(1)
    await expect.poll(() => document.querySelector('.problem-alert')?.textContent ?? '').toContain('The timeout is too long.')
  })

  it('bare tags and header_checks errors are visible', async () => {
    const server = fakeApi(routes({
      'PATCH /workspaces/acme/monitors/1': json({
        type: 'about:blank',
        title: 'The given data was invalid.',
        status: 422,
        errors: {
          'tags': ['Too many tags.'],
          'config.header_checks': ['Too many header checks.'],
        },
      }, 422),
    }))
    await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    fill('input[name="monitor_name"]', 'X')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(1)
    await flushPromises()
    expect(text()).toContain('Too many tags.')
    expect(text()).toContain('Too many header checks.')
  })

  it('malformed secret header lines are reported and never sent', async () => {
    const server = fakeApi(routes({
      'PATCH /workspaces/acme/monitors/1': json({ data: MONITOR }),
    }))
    await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    await expect.poll(exists('textarea[name="secrets_secret_headers"]')).not.toBeNull()
    const area = document.querySelector<HTMLTextAreaElement>('textarea[name="secrets_secret_headers"]')
    if (area) {
      area.value = 'no colon here'
      area.dispatchEvent(new Event('input', { bubbles: true }))
    }
    await submit('input[name="monitor_name"]')
    await flushPromises()
    expect(server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(0)
    expect(text()).toContain('not in the Name: value format')
  })

  it('secrets of a switched-off feature are not sent', async () => {
    const server = fakeApi(routes({
      'PATCH /workspaces/acme/monitors/1': json({ data: MONITOR }),
    }))
    const { wrapper } = await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="monitor_name"]')).not.toBeNull()
    await click('[data-test="secret-bearer_token-replace"]')
    await expect.poll(exists('input[name="secrets_bearer_token"]')).not.toBeNull()
    fill('input[name="secrets_bearer_token"]', 'typed')
    choose(wrapper, 'config-auth-type', 'none')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/monitors/1')[0]?.body).toEqual({
      config: {
        auth: { type: 'none', username: null },
      },
    })
  })

  it('a stored non-string json_query.expected is shown and preserved', async () => {
    const withJson = {
      ...MONITOR,
      config: {
        ...MONITOR.config,
        json_query: { path: '$.n', operator: '==', expected: 5 },
      },
    }
    const server = fakeApi(routes({
      'GET /workspaces/acme/monitors/1': json({ data: withJson }),
      'PATCH /workspaces/acme/monitors/1': json({ data: withJson }),
    }))
    await mountApp('/w/acme/monitors/1/edit')
    await expect.poll(exists('input[name="config_json_query_expected"]')).not.toBeNull()
    expect(value('input[name="config_json_query_expected"]')).toBe('5')
    fill('input[name="monitor_name"]', 'Only name')
    await submit('input[name="monitor_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/monitors/1')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/monitors/1')[0]?.body).toEqual({ name: 'Only name' })
  })
})
