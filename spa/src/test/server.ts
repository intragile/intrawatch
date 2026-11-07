import { vi } from 'vitest'

export interface RecordedCall {
  method: string
  path: string
  body: unknown
}

type Reply = Response | ((req: Request) => Response | Promise<Response>)

export function json(body: unknown, status = 200): Response {
  return new Response(status === 204
    ? null
    : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

export function problem(status: number, body: Record<string, unknown> = {}): Response {
  return new Response(JSON.stringify({
    type: 'about:blank',
    title: 'Problem',
    status,
    ...body,
  }), {
    status,
    headers: { 'Content-Type': 'application/problem+json' },
  })
}

/**
 * Stubs `fetch` with a route table keyed by `"METHOD /path"` (the `/api/v1` prefix is
 * stripped). An array answers in order and keeps repeating its last entry. The CSRF
 * cookie endpoint always succeeds.
 */
export function fakeApi(routes: Record<string, Reply | Reply[]>) {
  const calls: RecordedCall[] = []
  const unexpected: string[] = []
  document.cookie = 'XSRF-TOKEN=test-token; path=/'

  const fn = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const req = input instanceof Request
      ? input
      : new Request(new URL(String(input), window.location.origin), init)
    const path = new URL(req.url).pathname.replace(/^\/api\/v1/, '')
    if (path === '/sanctum/csrf-cookie')
      return new Response(null, { status: 204 })

    const text = await req.clone().text()
    let body: unknown
    if (text !== '') {
      try {
        body = JSON.parse(text)
      }
      catch {
        body = text
      }
    }
    const key = `${req.method} ${path}`
    calls.push({ method: req.method, path, body })

    const entry = routes[key]
    if (entry === undefined) {
      unexpected.push(key)
      return problem(599, { title: `Unexpected ${key}` })
    }
    const reply = Array.isArray(entry)
      ? (entry.length > 1
          ? entry.shift()
          : entry[0])
      : entry
    if (reply === undefined)
      throw new Error(`No reply left for ${key}`)
    return typeof reply === 'function'
      ? reply(req)
      : reply.clone()
  })
  vi.stubGlobal('fetch', fn)

  return {
    calls,
    unexpected,
    fn,
    of: (key: string) => calls.filter(c => `${c.method} ${c.path}` === key),
  }
}
