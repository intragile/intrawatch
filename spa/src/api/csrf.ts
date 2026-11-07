let pending: Promise<void> | null = null

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

/** Requests the CSRF cookie once per page lifetime; `force` requests a fresh one. */
export function ensureCsrf(force = false): Promise<void> {
  if (force || !pending) {
    const attempt = fetch('/sanctum/csrf-cookie', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then((res) => {
      if (!res.ok)
        throw new Error(`CSRF cookie request failed with ${res.status}`)
    })
    pending = attempt
    attempt.catch(() => {
      if (pending === attempt)
        pending = null
    })
  }
  return pending
}

/** Forgets the cached CSRF request so the next call fetches again. */
export function resetCsrf(): void {
  pending = null
}
