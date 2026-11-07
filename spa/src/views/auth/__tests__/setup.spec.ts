import { describe, expect, it } from 'vitest'
import { me, workspace } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'

describe('setup wizard', () => {
  it('setup wizard posts and lands on monitors', async () => {
    const browserZone = new Intl.DateTimeFormat().resolvedOptions().timeZone
    // A zone that differs from the browser's, so the follow-up PATCH is required.
    const serverZone = browserZone === 'Pacific/Chatham'
      ? 'Pacific/Apia'
      : 'Pacific/Chatham'
    const created = me({
      workspaces: [
        workspace({ timezone: serverZone }),
      ],
    })
    const server = fakeApi({
      'GET /setup': json({ required: true }),
      'POST /setup': json({ data: created }, 201),
      'GET /me': [
        json({ data: created }),
        json({
          data: me(),
        }),
      ],
      'PATCH /workspaces/acme': json({
        data: workspace({ timezone: browserZone }),
      }),
      'GET /workspaces/acme/monitors': json({
        data: [],
        meta: { next_cursor: null, limit: 100 },
      }),
      'GET /workspaces/acme/tags': json({
        data: [],
        meta: { next_cursor: null, limit: 100 },
      }),
    })
    const { router } = await mountApp('/')
    expect(router.currentRoute.value.name).toBe('setup')

    fill('input[name="name"]', 'Ada Lovelace')
    fill('input[name="email"]', 'ada@example.com')
    fill('input[name="password"]', 'correct horse battery')
    fill('input[name="password_confirmation"]', 'correct horse battery')
    await submit('input[name="name"]')

    await expect.poll(() => document.querySelector('input[name="workspace_name"]')).not.toBeNull()
    fill('input[name="workspace_name"]', 'Acme')
    await click('[data-test="setup-submit"]')

    await expect.poll(() => router.currentRoute.value.name).toBe('ws.monitors')
    expect(router.currentRoute.value.params.workspace).toBe('acme')
    expect(server.of('POST /setup')[0]?.body).toEqual({
      name: 'Ada Lovelace',
      email: 'ada@example.com',
      password: 'correct horse battery',
      workspace_name: 'Acme',
    })
    expect(server.of('PATCH /workspaces/acme')[0]?.body).toEqual({ name: 'Acme', timezone: browserZone })
    expect(server.unexpected).toEqual([])
  })

  it('setup wizard keeps the owner on step one while the passwords differ', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: true }),
    })
    await mountApp('/setup')

    fill('input[name="name"]', 'Ada Lovelace')
    fill('input[name="email"]', 'ada@example.com')
    fill('input[name="password"]', 'correct horse battery')
    fill('input[name="password_confirmation"]', 'something else entirely')
    await submit('input[name="name"]')

    await expect.poll(() => document.body.textContent).toContain('The passwords do not match')
    expect(document.querySelector('input[name="workspace_name"]')).toBeNull()
    expect(server.of('POST /setup')).toHaveLength(0)
  })
})
