/** RFC 9457 problem details, as returned by the API for every non-2xx response. */
export class ApiError extends Error {
  status: number
  type: string
  title: string
  detail?: string
  errors?: Record<string, string[]>

  constructor(status: number, type: string, title: string, detail?: string, errors?: Record<string, string[]>) {
    super(detail ?? title)
    this.name = 'ApiError'
    this.status = status
    this.type = type
    this.title = title
    this.detail = detail
    this.errors = errors
  }

  static async from(res: Response): Promise<ApiError> {
    const fallbackTitle = res.statusText || `HTTP ${res.status}`
    let body: unknown
    try {
      body = await res.json()
    }
    catch {
      return new ApiError(res.status, 'about:blank', fallbackTitle)
    }
    if (typeof body !== 'object' || body === null)
      return new ApiError(res.status, 'about:blank', fallbackTitle)

    const b = body as Record<string, unknown>
    return new ApiError(
      res.status,
      typeof b.type === 'string'
        ? b.type
        : 'about:blank',
      typeof b.title === 'string'
        ? b.title
        : (typeof b.message === 'string'
            ? b.message
            : fallbackTitle),
      typeof b.detail === 'string'
        ? b.detail
        : undefined,
      normaliseErrors(b.errors),
    )
  }

  static network(cause?: unknown): ApiError {
    const e = new ApiError(0, 'urn:client:network', 'Network error')
    e.cause = cause
    return e
  }
}

function normaliseErrors(raw: unknown): Record<string, string[]> | undefined {
  if (typeof raw !== 'object' || raw === null)
    return undefined
  const out: Record<string, string[]> = {}
  for (const [key, value] of Object.entries(raw)) {
    const messages = (Array.isArray(value)
      ? value
      : [value]).filter((m): m is string => typeof m === 'string')
    if (messages.length > 0)
      out[key] = messages
  }
  return out
}

/** First message per field. With a prefix, keys are made relative to it. */
export function fieldErrors(e: ApiError | null, prefix?: string): Record<string, string> {
  const out: Record<string, string> = {}
  if (!e?.errors)
    return out
  const head = prefix
    ? `${prefix}.`
    : ''
  for (const [key, messages] of Object.entries(e.errors)) {
    const first = messages[0]
    if (first === undefined || !key.startsWith(head))
      continue
    out[key.slice(head.length)] = first
  }
  return out
}

/**
 * The problem detail plus every message whose field is not rendered inline.
 * With a prefix, `knownFields` are relative to it, as in `fieldErrors`.
 */
export function formError(e: ApiError | null, knownFields: string[], prefix?: string): string | null {
  if (!e)
    return null
  const known = new Set(prefix
    ? knownFields.map(f => `${prefix}.${f}`)
    : knownFields)
  const lines: string[] = []
  if (e.detail)
    lines.push(e.detail)
  for (const [key, messages] of Object.entries(e.errors ?? {})) {
    if (!known.has(key))
      lines.push(...messages)
  }
  return lines.length > 0
    ? lines.join('\n')
    : null
}
