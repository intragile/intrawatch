import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, ensureCsrf, resetCsrf, setAuthHandlers, unwrap } from '..'
import { inspect, json, problem, stubFetch } from './helpers'

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
})
