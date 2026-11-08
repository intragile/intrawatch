/**
 * Runtime configuration the server injects into the shell as
 * `<meta name="intrawatch-config" content="{json}">`.
 */
export interface RuntimeConfig {
  version: string
  reverb: {
    key: string
  }
  locale: string
  import: {
    maxUploadMb: number
  }
}

const META_NAME = 'intrawatch-config'
const DEFAULT_MAX_UPLOAD_MB = 256

function fallback(): RuntimeConfig {
  return {
    version: 'dev',
    reverb: { key: import.meta.env.VITE_REVERB_KEY ?? '' },
    locale: 'en',
    import: { maxUploadMb: DEFAULT_MAX_UPLOAD_MB },
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function parse(raw: string): RuntimeConfig | null {
  let value: unknown
  try {
    value = JSON.parse(raw)
  }
  catch {
    return null
  }
  if (!isRecord(value) || !isRecord(value.reverb))
    return null

  const { version, locale } = value
  const { key } = value.reverb
  if (typeof version !== 'string' || typeof locale !== 'string' || typeof key !== 'string')
    return null

  const maxUploadMb = isRecord(value.import)
    ? value.import.max_upload_mb
    : undefined

  return {
    version,
    reverb: { key },
    locale,
    import: {
      maxUploadMb: typeof maxUploadMb === 'number' && Number.isFinite(maxUploadMb) && maxUploadMb > 0
        ? maxUploadMb
        : DEFAULT_MAX_UPLOAD_MB,
    },
  }
}

/**
 * Reads the configuration from the document. Without the meta tag (the Vite dev
 * server) or with an unreadable one, it falls back to development defaults.
 */
export function readRuntimeConfig(doc: Document = document): RuntimeConfig {
  const content = doc.querySelector<HTMLMetaElement>(`meta[name="${META_NAME}"]`)?.content

  return (content !== undefined && parse(content)) || fallback()
}
