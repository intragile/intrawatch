import { ApiError } from './problem'

let pending: Promise<void> | null = null
let refreshing: Promise<void> | null = null

/** Reads the Sanctum `XSRF-TOKEN` cookie, URI-decoded as the header value requires. */
export function readXsrfToken(): string | null {
  const match = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))
  if (!match)
    return null
  const raw = match.slice('XSRF-TOKEN='.length)
  try {
    return decodeURIComponent(raw)
  }
  catch {
    return raw
  }
}

async function requestCookie(): Promise<void> {
  let res: Response
  try {
    res = await globalThis.fetch('/sanctum/csrf-cookie', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
  }
  catch (cause) {
    throw ApiError.network(cause)
  }
  if (!res.ok)
    throw await ApiError.from(res)
}

/**
 * Requests the CSRF cookie once per page lifetime; `force` requests a fresh one.
 * Concurrent forced refreshes share one in-flight request. Failures are `ApiError`s.
 */
export function ensureCsrf(force = false): Promise<void> {
  if (force && refreshing)
    return refreshing
  if (force || !pending) {
    const attempt = requestCookie()
    pending = attempt
    if (force)
      refreshing = attempt
    const clear = () => {
      if (pending === attempt)
        pending = null
      if (refreshing === attempt)
        refreshing = null
    }
    attempt.then(() => {
      if (refreshing === attempt)
        refreshing = null
    }, clear)
  }
  return pending as Promise<void>
}

/** Forgets the cached CSRF request so the next call fetches again. */
export function resetCsrf(): void {
  pending = null
  refreshing = null
}
