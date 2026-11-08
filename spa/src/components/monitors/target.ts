import type { Monitor } from '../../api/types'

function text(value: unknown): string | null {
  return typeof value === 'string' && value !== ''
    ? value
    : null
}

/** What a monitor watches, in the short form shown under its name: a host, URL, record or schedule. */
export function monitorTarget(monitor: Pick<Monitor, 'type' | 'config'>): string {
  const c = monitor.config
  switch (monitor.type) {
    case 'http':
      return (text(c.url) ?? '').replace(/^https?:\/\//, '')
    case 'tcp':
    case 'tls': {
      const host = text(c.host) ?? ''
      return typeof c.port === 'number' && host !== ''
        ? `${host}:${c.port}`
        : host
    }
    case 'ping':
      return text(c.host) ?? ''
    case 'dns':
      return [
        text(c.record_type),
        text(c.name),
      ].filter(Boolean).join(' ')
    case 'domain':
      return text(c.domain) ?? ''
    case 'cron':
      return text(c.schedule) ?? ''
    default:
      return ''
  }
}
