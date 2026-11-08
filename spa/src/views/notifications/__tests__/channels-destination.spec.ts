import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import fixture from '../../../components/notifications/__tests__/channel-types.fixture.json'
import { useNotify } from '../../../composables/useNotify'
import en from '../../../i18n/en.json'
import hu from '../../../i18n/hu.json'
import { meWith } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'
import { destinationChanged } from '../../../utils/secrets'

const REQUIRED = en.validation.required
const text = () => document.body.textContent ?? ''
const exists = (selector: string) => () => document.querySelector(selector)
const keysOf = (type: string): string[] => fixture.find(t => t.type === type)?.destination_keys ?? []

function channel(over: Record<string, unknown> = {}) {
  return {
    id: 3,
    name: 'Ops chat',
    type: 'telegram',
    config: {
      chat_id: '-100123456',
      message_thread_id: null,
      server_url: 'https://api.telegram.org',
      silent: false,
    },
    secret_keys: ['bot_token'],
    templates: null,
    is_default: false,
    active: true,
    monitor_count: 0,
    last_success_at: null,
    last_failure_at: null,
    last_error: null,
    created_at: null,
    updated_at: null,
    ...over,
  }
}

const WEBHOOK = channel({
  type: 'webhook',
  config: {
    url: 'https://hooks.example.test/in',
    method: 'POST',
    format: 'json',
    headers: [],
  },
  secret_keys: ['signing_secret'],
})

function routes(data: ReturnType<typeof channel>) {
  return {
    'GET /setup': json({ required: false }),
    'GET /me': json({
      data: meWith({}),
    }),
    'GET /notification-channel-types': json(fixture),
    'GET /workspaces/acme/notification-channels/3': json({ data }),
    'PATCH /workspaces/acme/notification-channels/3': json({ data }),
  }
}

/** Dotted paths of every message of a locale. */
function messageKeys(messages: object, prefix = ''): string[] {
  return Object.entries(messages).flatMap(([key, value]) => typeof value === 'object' && value !== null
    ? messageKeys(value as object, `${prefix}${key}.`)
    : [`${prefix}${key}`])
}

function secretBlock(name: string): string {
  return document.querySelector(`[data-test="secret-${name}-set"]`)?.closest('.secret-field')?.textContent ?? ''
}

describe('channel editor: secrets follow the destination', () => {
  beforeEach(() => {
    useNotify().notices.value = []
  })

  it('a new server url needs the stored bot token again before anything is sent', async () => {
    const server = fakeApi(routes(channel()))
    await mountApp('/w/acme/channels/3')
    await expect.poll(exists('input[name="config_server_url"]')).not.toBeNull()

    fill('input[name="config_server_url"]', 'https://tg.example.test')
    await submit('input[name="channel_name"]')
    await flushPromises()
    expect(server.of('PATCH /workspaces/acme/notification-channels/3')).toHaveLength(0)
    expect(text()).toContain(REQUIRED)

    await click('[data-test="secret-bot_token-replace"]')
    await expect.poll(exists('input[name="secrets_bot_token"]')).not.toBeNull()
    const token = `123456:${'b'.repeat(35)}`
    fill('input[name="secrets_bot_token"]', token)
    await submit('input[name="channel_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/notification-channels/3')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/notification-channels/3')[0]?.body).toEqual({
      config: { server_url: 'https://tg.example.test' },
      secrets: { bot_token: token },
    })
  })

  it('another field is saved without the secrets', async () => {
    const server = fakeApi(routes(channel()))
    await mountApp('/w/acme/channels/3')
    await expect.poll(exists('input[name="config_chat_id"]')).not.toBeNull()

    fill('input[name="config_chat_id"]', '-100999')
    await submit('input[name="channel_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/notification-channels/3')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/notification-channels/3')[0]?.body).toEqual({
      config: { chat_id: '-100999' },
    })
    expect(text()).not.toContain(REQUIRED)
  })

  it('an optional secret becomes required with the url and may be cleared instead', async () => {
    const server = fakeApi(routes(WEBHOOK))
    await mountApp('/w/acme/channels/3')
    await expect.poll(exists('input[name="config_url"]')).not.toBeNull()
    expect(secretBlock('signing_secret')).not.toContain(`${en.channels.fields.signing_secret} *`)

    fill('input[name="config_url"]', 'https://elsewhere.example.test/in')
    await flushPromises()
    expect(secretBlock('signing_secret')).toContain(`${en.channels.fields.signing_secret} *`)

    await submit('input[name="channel_name"]')
    await flushPromises()
    expect(server.of('PATCH /workspaces/acme/notification-channels/3')).toHaveLength(0)
    expect(text()).toContain(REQUIRED)

    await click('[data-test="secret-signing_secret-clear"]')
    await submit('input[name="channel_name"]')
    await expect.poll(() => server.of('PATCH /workspaces/acme/notification-channels/3')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/notification-channels/3')[0]?.body).toEqual({
      config: { url: 'https://elsewhere.example.test/in' },
      secrets: { signing_secret: null },
    })
  })

  it('destination changes match the server destination keys', () => {
    const smtp = {
      host: 'smtp.example.test',
      port: 587,
      encryption: 'starttls',
      username: 'a',
      from_address: 'a@example.test',
      from_name: null,
    }
    expect(destinationChanged(keysOf('email'), {
      to: ['a@example.test'],
      smtp,
    }, {
      to: ['b@example.test'],
      smtp,
    })).toBe(false)
    expect(destinationChanged(keysOf('email'), { smtp }, {
      smtp: { ...smtp, username: 'b' },
    })).toBe(false)
    expect(destinationChanged(keysOf('email'), { smtp }, {
      smtp: { ...smtp, host: 'evil.example.test' },
    })).toBe(true)
    expect(destinationChanged(keysOf('email'), { smtp }, {
      smtp: { ...smtp, port: 2525 },
    })).toBe(true)
    expect(destinationChanged(keysOf('email'), { smtp }, { smtp: null })).toBe(true)
    expect(destinationChanged(keysOf('opsgenie'), { region: 'us' }, { region: 'eu' })).toBe(true)
    expect(destinationChanged(keysOf('ntfy'), { server_url: 'https://ntfy.sh', topic: 'a' }, { server_url: 'https://ntfy.sh', topic: 'b' })).toBe(false)
    expect(destinationChanged(keysOf('slack'), {}, {})).toBe(false)
  })

  it('reuses the existing required message and adds no locale keys of its own', () => {
    expect(en.validation.required).toBeTypeOf('string')
    expect(hu.validation.required).toBeTypeOf('string')
    const keys = messageKeys(en)
    expect(messageKeys(hu).sort()).toEqual([...keys].sort())
    expect(keys.filter(key => /reenter|re_enter|destination/i.test(key))).toEqual([])
  })
})
