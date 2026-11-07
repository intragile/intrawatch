import { vi } from 'vitest'

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

export function json(body: unknown, status = 200): Response {
  return new Response(status === 204
    ? null
    : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

export function stubFetch(...responses: Array<Response | Error>) {
  const queue = [...responses]
  const fn = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => {
    const next = queue.shift()
    if (!next)
      throw new Error('unexpected fetch call')
    if (next instanceof Error)
      throw next
    return next
  })
  vi.stubGlobal('fetch', fn)
  return fn
}

/** Normalises a stubbed fetch call into method, URL and headers. */
export function inspect(call: unknown[]) {
  const [input, init] = call as [RequestInfo | URL, RequestInit | undefined]
  const req = input instanceof Request
    ? input
    : new Request(new URL(String(input), 'http://localhost'), init)
  return {
    method: req.method,
    url: new URL(req.url, 'http://localhost'),
    headers: req.headers,
  }
}
