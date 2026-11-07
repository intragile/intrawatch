import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { me, workspace } from '../../test/fixtures'
import { fakeApi, json, problem } from '../../test/server'
import { FOCUS_REFRESH_MS, useSession } from '../session'

describe('session store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('load stores me and clears it on 401', async () => {
    fakeApi({
      'GET /me': [
        json({
          data: me(),
        }),
        problem(401),
      ],
    })
    const session = useSession()
    await session.load()
    expect(session.user?.email).toBe('ada@example.com')
    expect(session.workspace('acme')?.name).toBe('Acme')

    await session.load()
    expect(session.me).toBeNull()
    expect(session.loaded).toBe(true)
  })

  it('current follows the route slug', async () => {
    const session = useSession()
    session.me = me({
      workspaces: [
        workspace(),
        workspace({ slug: 'beta', name: 'Beta' }),
      ],
    })
    session.currentSlug = 'beta'
    expect(session.current?.name).toBe('Beta')
    session.currentSlug = 'missing'
    expect(session.current).toBeNull()
  })

  it('home workspace prefers the last used one', () => {
    const session = useSession()
    session.me = me({
      workspaces: [
        workspace(),
        workspace({ slug: 'beta' }),
      ],
    })
    expect(session.homeWorkspace()).toBe('acme')
    session.rememberWorkspace('beta')
    expect(session.homeWorkspace()).toBe('beta')
    session.rememberWorkspace('gone')
    expect(session.homeWorkspace()).toBe('acme')
  })

  it('load re-runs on window focus at most every 30 s', async () => {
    const server = fakeApi({
      'GET /me': json({
        data: me(),
      }),
    })
    const now = vi.spyOn(Date, 'now').mockReturnValue(1_000_000)
    const session = useSession()
    await session.load()
    const stop = session.watchFocus()

    window.dispatchEvent(new Event('focus'))
    await vi.waitFor(() => expect(server.of('GET /me')).toHaveLength(1))

    now.mockReturnValue(1_000_000 + FOCUS_REFRESH_MS)
    window.dispatchEvent(new Event('focus'))
    await vi.waitFor(() => expect(server.of('GET /me')).toHaveLength(2))

    stop()
    now.mockReturnValue(1_000_000 + 3 * FOCUS_REFRESH_MS)
    window.dispatchEvent(new Event('focus'))
    await new Promise(r => setTimeout(r, 0))
    expect(server.of('GET /me')).toHaveLength(2)
  })

  it('login reports a pending two-factor challenge without loading me', async () => {
    const server = fakeApi({
      'POST /auth/login': json({ two_factor: true }),
    })
    const session = useSession()
    await expect(session.login('ada@example.com', 'secret-password', true)).resolves.toBe('two-factor')
    expect(session.twoFactorPending).toBe(true)
    expect(server.of('GET /me')).toHaveLength(0)
    expect(server.of('POST /auth/login')[0]?.body).toEqual({ email: 'ada@example.com', password: 'secret-password', remember: '1' })
  })
})
