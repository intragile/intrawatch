import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { useSession } from '../../stores/session'
import { me, workspace } from '../../test/fixtures'
import { fill, mountApp, submit } from '../../test/mount'
import { fakeApi, json, problem } from '../../test/server'
import { safeRedirect } from '../guards'

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

  it('safeRedirect only accepts same-application paths', () => {
    const home = { name: 'home' }
    expect(safeRedirect('//evil.com')).toEqual(home)
    expect(safeRedirect('https://evil.com')).toEqual(home)
    expect(safeRedirect('/\\evil')).toEqual(home)
    expect(safeRedirect(['/w/acme/monitors'])).toEqual(home)
    expect(safeRedirect(undefined)).toEqual(home)
    expect(safeRedirect('/w/acme/monitors?x=1')).toBe('/w/acme/monitors?x=1')
  })

  it('an unknown workspace slug goes home', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me(),
      }),
    })
    const { router } = await mountApp('/w/nope/monitors')

    expect(router.currentRoute.value.params.workspace).toBe('acme')
    expect(router.currentRoute.value.name).toBe('ws.monitors')
  })

  it('a user without workspaces sees the empty state on home', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me({ workspaces: [] }),
      }),
    })
    const { router } = await mountApp('/')

    expect(router.currentRoute.value.name).toBe('home')
    await expect.poll(() => document.body.textContent).toContain('No workspace yet')
  })

  it('leaves the workspace when a refresh no longer lists it', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        json({
          data: me({
            workspaces: [
              workspace(),
              workspace({ id: '01JBBBBBBBBBBBBBBBBBBBBBBB', slug: 'beta', name: 'Beta' }),
            ],
          }),
        }),
        json({
          data: me({
            workspaces: [
              workspace({ id: '01JBBBBBBBBBBBBBBBBBBBBBBB', slug: 'beta', name: 'Beta' }),
            ],
          }),
        }),
      ],
    })
    const { router } = await mountApp('/w/acme/members')
    await expect.poll(() => router.currentRoute.value.name).toBe('ws.members')

    await useSession().load()
    await nextTick()

    await expect.poll(() => router.currentRoute.value.params.workspace).toBe('beta')
    expect(router.currentRoute.value.name).toBe('ws.monitors')
  })
})
