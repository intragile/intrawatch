import { describe, expect, it } from 'vitest'
import { me } from '../../test/fixtures'
import { fill, mountApp, submit } from '../../test/mount'
import { fakeApi, json, problem } from '../../test/server'

describe('router guard', () => {
  it('guard sends to setup when setup is required', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: true }),
    })
    const { router } = await mountApp('/w/acme/monitors')

    expect(router.currentRoute.value.name).toBe('setup')
    expect(server.of('GET /me')).toHaveLength(0)
  })

  it('guard sends to login and back to the deep link after login', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        problem(401),
        json({
          data: me(),
        }),
      ],
      'POST /auth/login': json({ two_factor: false }),
    })
    const { router } = await mountApp('/w/acme/monitors/5')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/w/acme/monitors/5')

    fill('input[name="email"]', 'ada@example.com')
    fill('input[name="password"]', 'correct horse battery')
    await submit('input[name="email"]')

    await expect.poll(() => router.currentRoute.value.fullPath).toBe('/w/acme/monitors/5')
    expect(router.currentRoute.value.name).toBe('ws.monitor')
    expect(server.of('POST /auth/login')[0]?.body).toMatchObject({
      email: 'ada@example.com',
      password: 'correct horse battery',
    })
  })
})
