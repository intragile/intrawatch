import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, ensureCsrf, resetCsrf, setAuthHandlers, unwrap } from '..'
import { i18n } from '../../i18n'
import { inspect, json, problem, stubFetch } from './helpers'

// Untyped handle for exercising paths regardless of the generated route table.
const loose = api as unknown as Record<'GET' | 'PUT', (path: string, init?: unknown) => Promise<unknown>>

function setCookie(value: string) {
  document.cookie = `XSRF-TOKEN=${value}; path=/`
}

describe('api client', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
    resetCsrf()
    setCookie('abc%3D%3D')
    setAuthHandlers({
      onUnauthenticated: async () => false,
      onPasswordConfirmRequired: async () => false,
    })
  })

  it('sends XSRF header only on unsafe methods', async () => {
    const fetch = stubFetch(json({
      data: { id: 1 },
    }), json({ csrf: 1 }, 204), json({
      data: { id: 1 },
    }))
    await api.GET('/me')
    await api.POST('/auth/logout')

    const calls = fetch.mock.calls.map(inspect)
    const get = calls.find(c => c.method === 'GET' && c.url.pathname === '/api/v1/me')!
    const post = calls.find(c => c.method === 'POST')!
    expect(get.headers.get('X-XSRF-TOKEN')).toBeNull()
    expect(get.headers.get('Accept')).toBe('application/json')
    expect(post.headers.get('X-XSRF-TOKEN')).toBe('abc==')
    expect(post.headers.get('Accept')).toBe('application/json')
  })

  it('parses problem+json into ApiError with errors', async () => {
    stubFetch(problem(422, {
      title: 'Invalid',
      detail: 'Bad',
      errors: {
        email: ['Required'],
      },
    }))
    const err = await api.GET('/me').catch(e => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err).toMatchObject({
      status: 422,
      type: 'about:blank',
      title: 'Invalid',
      detail: 'Bad',
      errors: {
        email: ['Required'],
      },
    })
  })

  it('non-json 500 becomes about:blank ApiError', async () => {
    stubFetch(new Response('<html>boom</html>', {
      status: 500,
      statusText: 'Internal Server Error',
      headers: { 'Content-Type': 'text/html' },
    }))
    const err = await api.GET('/me').catch(e => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err).toMatchObject({ status: 500, type: 'about:blank', title: 'Internal Server Error' })
  })

  it('network failure becomes status 0', async () => {
    stubFetch(new TypeError('Failed to fetch'))
    const err = await api.GET('/me').catch(e => e)
    expect(err).toMatchObject({ status: 0, type: 'urn:client:network' })
  })

  it('client recovers from 419 once and surfaces a second 419', async () => {
    const fetch = stubFetch(
      json({}, 204), // initial csrf cookie
      problem(419), // first attempt
      json({}, 204), // forced csrf refresh
      json({
        data: { ok: true },
      }), // retry succeeds
    )
    const res = await api.POST('/auth/logout')
    expect(res.response.status).toBe(200)
    expect(fetch.mock.calls.filter(c => inspect(c).url.pathname === '/sanctum/csrf-cookie')).toHaveLength(2)

    const again = stubFetch(json({}, 204), problem(419), json({}, 204), problem(419))
    resetCsrf()
    const err = await api.POST('/auth/logout').catch(e => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.status).toBe(419)
    expect(again).toHaveBeenCalledTimes(4)
  })

  it('401 asks the handler and retries once when it resolves true', async () => {
    const onUnauthenticated = vi.fn(async () => true)
    setAuthHandlers({
      onUnauthenticated,
      onPasswordConfirmRequired: async () => false,
    })
    const fetch = stubFetch(problem(401), json({
      data: { id: 1 },
    }))
    await api.GET('/workspaces')
    expect(onUnauthenticated).toHaveBeenCalledOnce()
    expect(fetch).toHaveBeenCalledTimes(2)

    stubFetch(problem(401), problem(401))
    const err = await api.GET('/workspaces').catch(e => e)
    expect(err.status).toBe(401)
    expect(onUnauthenticated).toHaveBeenCalledTimes(2)
  })

  it('401 does not retry when the handler resolves false and exempts auth endpoints', async () => {
    const onUnauthenticated = vi.fn(async () => true)
    setAuthHandlers({
      onUnauthenticated,
      onPasswordConfirmRequired: async () => false,
    })
    stubFetch(problem(401))
    expect((await api.GET('/me').catch(e => e)).status).toBe(401)
    expect(onUnauthenticated).not.toHaveBeenCalled()
  })

  it('423 asks for password confirmation and retries once', async () => {
    const onPasswordConfirmRequired = vi.fn(async () => true)
    setAuthHandlers({
      onUnauthenticated: async () => false,
      onPasswordConfirmRequired,
    })
    const fetch = stubFetch(json({}, 204), problem(423), json({ data: {} }))
    await api.POST('/auth/logout')
    expect(onPasswordConfirmRequired).toHaveBeenCalledOnce()
    expect(fetch.mock.calls.filter(c => inspect(c).url.pathname === '/api/v1/auth/logout')).toHaveLength(2)
  })

  it('ensureCsrf fetches the cookie once per page lifetime', async () => {
    const fetch = stubFetch(json({}, 204))
    await ensureCsrf()
    await ensureCsrf()
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('unwrap returns the data envelope and throws on error', async () => {
    expect(await unwrap(Promise.resolve({
      data: {
        data: [1],
      },
    }))).toEqual([1])
    await expect(unwrap(Promise.resolve({
      error: { title: 'x' },
    }))).rejects.toBeInstanceOf(ApiError)
  })

  it('csrf network failure and non-ok status surface as ApiError', async () => {
    stubFetch(new TypeError('offline'))
    const net = await ensureCsrf().catch(e => e)
    expect(net).toBeInstanceOf(ApiError)
    expect(net.status).toBe(0)
    expect(net.type).toBe('urn:client:network')

    stubFetch(problem(500, { title: 'Broken' }))
    const bad = await api.POST('/auth/logout').catch(e => e)
    expect(bad).toBeInstanceOf(ApiError)
    expect(bad).toMatchObject({ status: 500, title: 'Broken' })

    const retry = stubFetch(json({}, 204))
    await ensureCsrf()
    expect(retry).toHaveBeenCalledTimes(1)
  })

  it('concurrent 419 recoveries share one forced csrf refresh', async () => {
    let csrfCalls = 0
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const { url } = inspect([input])
      if (url.pathname === '/sanctum/csrf-cookie') {
        csrfCalls++
        await new Promise(r => setTimeout(r, 5))
        return json({}, 204)
      }
      return csrfCalls < 2
        ? problem(419)
        : json({ data: {} })
    }))
    await ensureCsrf()
    await Promise.all([
      api.POST('/auth/logout'),
      api.POST('/auth/logout'),
      api.POST('/auth/logout'),
    ])
    expect(csrfCalls).toBe(2)
  })

  it.each([
    [
      '419',
      () => [
        problem(419),
        json({}, 204),
        json({ data: {} }),
      ],
    ],
    [
      '401',
      () => [
        problem(401),
        json({ data: {} }),
      ],
    ],
  ])('retried request after %s carries the same JSON body', async (kind, rest) => {
    setAuthHandlers({
      onUnauthenticated: async () => true,
      onPasswordConfirmRequired: async () => false,
    })
    const fetch = stubFetch(json({}, 204), ...rest())
    await loose.PUT('/workspaces/{workspace}', {
      params: {
        path: { workspace: 'w' },
      },
      body: { name: 'N' },
    })
    const bodies = await Promise.all(
      fetch.mock.calls
        .filter(c => inspect(c).url.pathname === '/api/v1/workspaces/w')
        .map(c => (c[0] as Request).text()),
    )
    expect(bodies).toHaveLength(2)
    expect(bodies[0]).toContain('"name":"N"')
    expect(bodies[1]).toBe(bodies[0])
    expect(kind).toBeTruthy()
  })

  it('exempts auth endpoints exactly and not by suffix', async () => {
    const onUnauthenticated = vi.fn(async () => false)
    setAuthHandlers({
      onUnauthenticated,
      onPasswordConfirmRequired: async () => false,
    })
    stubFetch(json({}, 204), problem(401))
    await api.POST('/auth/logout').catch(() => {})
    expect(onUnauthenticated).not.toHaveBeenCalled()

    stubFetch(problem(401))
    await loose.GET('/workspaces/{workspace}/monitors/{monitor}/pause', {
      params: {
        path: { workspace: 'w', monitor: 'm' },
      },
    }).catch(() => {})
    stubFetch(problem(401))
    await loose.GET('/workspaces/{workspace}/me', {
      params: {
        path: { workspace: 'w' },
      },
    }).catch(() => {})
    expect(onUnauthenticated).toHaveBeenCalledTimes(2)
  })

  it('unwrap resolves undefined for a 204 and keeps real errors', async () => {
    expect(await unwrap<void>(Promise.resolve({}))).toBeUndefined()
    const boom = new TypeError('x')
    await expect(unwrap(Promise.resolve({ error: boom }))).rejects.toBe(boom)
    const odd = (await unwrap(Promise.resolve({ error: 'str' })).catch((e: unknown) => e)) as ApiError
    expect(odd).toBeInstanceOf(ApiError)
    expect(odd.status).not.toBe(0)
  })
})

describe('api client Accept-Language', () => {
  it('sends the current locale on every request and follows changes', async () => {
    vi.unstubAllGlobals()
    const fetch = stubFetch(json({ data: {} }), json({ data: {} }))
    i18n.global.locale.value = 'en'
    await api.GET('/me')
    i18n.global.locale.value = 'hu'
    await api.GET('/me')
    i18n.global.locale.value = 'en'
    const calls = fetch.mock.calls.map(inspect)
    expect(calls[0]?.headers.get('Accept-Language')).toBe('en')
    expect(calls[1]?.headers.get('Accept-Language')).toBe('hu')
  })
})
