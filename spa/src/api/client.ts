import type { paths } from './schema'
import createClient from 'openapi-fetch'
import { ensureCsrf, readXsrfToken } from './csrf'
import { ApiError } from './problem'

export interface AuthHandlers {
  /** Resolves true when the session was restored and the request may be retried. */
  onUnauthenticated: () => Promise<boolean>
  /** Resolves true when the password was confirmed and the request may be retried. */
  onPasswordConfirmRequired: () => Promise<boolean>
}

let handlers: AuthHandlers = {
  onUnauthenticated: async () => false,
  onPasswordConfirmRequired: async () => false,
}

export function setAuthHandlers(next: AuthHandlers): void {
  handlers = next
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])
// A 401 here is an expected answer, not an expired session.
const UNAUTHENTICATED_EXEMPT = [
  '/auth/login',
  '/auth/two-factor-challenge',
  '/setup',
  '/me',
]

function isExempt(url: string): boolean {
  const { pathname } = new URL(url, window.location.origin)
  return UNAUTHENTICATED_EXEMPT.some(suffix => pathname.endsWith(suffix))
}

async function attempt(template: Request): Promise<Response> {
  const request = template.clone()
  request.headers.set('Accept', 'application/json')
  if (!SAFE_METHODS.has(request.method)) {
    await ensureCsrf()
    const token = readXsrfToken()
    if (token)
      request.headers.set('X-XSRF-TOKEN', token)
  }
  try {
    return await globalThis.fetch(request)
  }
  catch (cause) {
    throw ApiError.network(cause)
  }
}

/** Sends the request with CSRF handling and at most one recovery retry per failure kind. */
async function send(template: Request): Promise<Response> {
  const retried = { csrf: false, auth: false, confirm: false }
  for (;;) {
    const res = await attempt(template)
    if (res.ok)
      return res

    if (res.status === 419 && !retried.csrf) {
      retried.csrf = true
      await ensureCsrf(true)
      continue
    }
    if (res.status === 401 && !retried.auth && !isExempt(res.url || template.url)) {
      retried.auth = true
      if (await handlers.onUnauthenticated())
        continue
    }
    else if (res.status === 423 && !retried.confirm) {
      retried.confirm = true
      if (await handlers.onPasswordConfirmRequired())
        continue
    }
    throw await ApiError.from(res)
  }
}

// Request objects need an absolute URL; the API is always served from the page's own origin.
export const api = createClient<paths>({
  baseUrl: `${window.location.origin}/api/v1`,
  credentials: 'same-origin',
  fetch: send,
})

/** Unwraps a `{data: ...}` envelope; failures surface as `ApiError`. */
export async function unwrap<T>(p: Promise<{ data?: { data: T }, error?: unknown }>): Promise<T> {
  const result = await p
  if (result.error !== undefined && result.error !== null) {
    throw result.error instanceof ApiError
      ? result.error
      : new ApiError(0, 'about:blank', 'Unexpected API error')
  }
  return (result.data as { data: T }).data
}
