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
}

const META_NAME = 'intrawatch-config'

function fallback(): RuntimeConfig {
  return {
    version: 'dev',
    reverb: { key: import.meta.env.VITE_REVERB_KEY ?? '' },
    locale: 'en',
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

  return {
    version,
    reverb: { key },
    locale,
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
