import type { Monitor, MonitorType } from '../../../api/types'

export type Config = Record<string, unknown>

/** Secrets the user touched: a string replaces the stored value, `null` clears it. Untouched keys are absent. */
export type SecretDraft = Record<string, string | null | undefined>

export interface TagAssignment {
  id: number
  value: string | null
}

/** One entry of `GET /monitor-types`. */
export interface MonitorTypeInfo {
  type: MonitorType
  default_interval_seconds: number
  min_interval_seconds: number
  supports_inverted: boolean
  scheduled: boolean
  defaults: Config
}

type Scalars = Pick<Monitor, 'name' | 'description' | 'active' | 'interval_seconds' | 'timeout_seconds' | 'retries' | 'retry_interval_seconds' | 'degraded_threshold_ms' | 'inverted' | 'parent_id'>

/** The editable part of a monitor. Tags and channel ids are normalised from the loosely typed response. */
export interface MonitorForm extends Scalars {
  config: Config
  tags: TagAssignment[]
  notification_channel_ids: number[]
  alerting: Monitor['alerting']
}

/** Props every type-specific field group receives. */
export interface TypeFieldProps {
  /** Config validation messages, keyed relative to `config.` (e.g. `header_checks.0.value`). */
  errors: Record<string, string>
  /** Keys of the secrets already stored on the monitor. */
  secretKeys: string[]
  /** Secret validation messages, keyed relative to `secrets.`. */
  secretErrors: Record<string, string>
}

/** Secrets that are maps; they are edited as `Name: value` lines. */
const SECRET_MAP_KEYS = new Set(['secret_headers'])

/** Defaults for fields the type catalogue does not describe. */
const DEFAULT_TIMEOUT_SECONDS = 10
const DEFAULT_RETRIES = 1

export function clone<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T
}

export function deepEqual(a: unknown, b: unknown): boolean {
  if (a === b)
    return true
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null)
    return false
  if (Array.isArray(a) !== Array.isArray(b))
    return false
  const ka = Object.keys(a)
  const kb = Object.keys(b)
  if (ka.length !== kb.length)
    return false
  return ka.every(k => deepEqual((a as Record<string, unknown>)[k], (b as Record<string, unknown>)[k]))
}

/** The message for a field, or for the first of its nested keys (`accepted_status` matches `accepted_status.0`). */
export function errorFor(errors: Record<string, string>, key: string): string | undefined {
  const exact = errors[key]
  if (exact !== undefined)
    return exact
  const prefix = `${key}.`
  const nested = Object.keys(errors).find(k => k.startsWith(prefix))
  return nested === undefined
    ? undefined
    : errors[nested]
}

/** The tags of a monitor response, which carries `{id, value}` objects although the schema types names. */
function tagsOf(monitor: Monitor): TagAssignment[] {
  const raw: unknown = monitor.tags
  if (!Array.isArray(raw))
    return []
  const out: TagAssignment[] = []
  for (const entry of raw) {
    if (typeof entry !== 'object' || entry === null)
      continue
    const { id, value } = entry as { id?: unknown, value?: unknown }
    if (typeof id === 'number') {
      out.push({
        id,
        value: typeof value === 'string'
          ? value
          : null,
      })
    }
  }
  return out
}

export function formFromMonitor(monitor: Monitor): MonitorForm {
  return {
    name: monitor.name,
    description: monitor.description,
    active: monitor.active,
    interval_seconds: monitor.interval_seconds,
    timeout_seconds: monitor.timeout_seconds,
    retries: monitor.retries,
    retry_interval_seconds: monitor.retry_interval_seconds,
    degraded_threshold_ms: monitor.degraded_threshold_ms,
    inverted: monitor.inverted,
    parent_id: monitor.parent_id,
    config: clone(monitor.config),
    tags: tagsOf(monitor),
    notification_channel_ids: monitor.notification_channel_ids.map(Number),
    alerting: { ...monitor.alerting },
  }
}

export function newForm(info: MonitorTypeInfo, channelIds: number[] = []): MonitorForm {
  return {
    name: '',
    description: null,
    active: true,
    interval_seconds: info.default_interval_seconds,
    timeout_seconds: DEFAULT_TIMEOUT_SECONDS,
    retries: DEFAULT_RETRIES,
    retry_interval_seconds: info.default_interval_seconds,
    degraded_threshold_ms: null,
    inverted: false,
    parent_id: null,
    config: clone(info.defaults),
    tags: [],
    notification_channel_ids: channelIds,
    alerting: {
      resend_interval_minutes: 0,
      notify_degraded: false,
      degraded_alert_after_seconds: 0,
      notify_changes: false,
    },
  }
}

/** Parses `Name: value` lines into a header map; blank lines and lines without a name are skipped. */
export function parseHeaderLines(source: string): Record<string, string> {
  const out: Record<string, string> = {}
  for (const line of source.split('\n')) {
    const at = line.indexOf(':')
    if (at < 1)
      continue
    const name = line.slice(0, at).trim()
    if (name !== '')
      out[name] = line.slice(at + 1).trim()
  }
  return out
}

/** One-based numbers of the non-blank lines that are not in `Name: value` form. */
export function invalidHeaderLines(source: string): number[] {
  const bad: number[] = []
  source.split('\n').forEach((line, i) => {
    if (line.trim() === '')
      return
    const at = line.indexOf(':')
    if (at < 1 || line.slice(0, at).trim() === '')
      bad.push(i + 1)
  })
  return bad
}

/** The secret keys whose controls are on screen for the given type and config. */
export function renderedSecrets(type: MonitorType, config: Config): string[] {
  if (type !== 'http')
    return []
  const auth = typeof config.auth === 'object' && config.auth !== null
    ? (config.auth as Record<string, unknown>).type
    : undefined
  const keys = ['secret_headers']
  if (auth === 'basic')
    keys.push('basic_password')
  if (auth === 'bearer')
    keys.push('bearer_token')
  if (config.mtls === true)
    keys.push('mtls_cert', 'mtls_key')
  if (config.proxy !== null && config.proxy !== undefined)
    keys.push('proxy_password')
  return keys
}

/** Drops drafts of secrets whose feature the user has switched off. */
export function pruneSecrets(type: MonitorType, config: Config, draft: SecretDraft): SecretDraft {
  const allowed = new Set(renderedSecrets(type, config))
  return Object.fromEntries(Object.entries(draft).filter(([key]) => allowed.has(key)))
}

/**
 * The secrets to send: only touched keys. An empty string is never sent (the API would read it as a
 * clear), and a header list that parses to nothing is skipped rather than wiping the stored map.
 */
export function secretsPayload(draft: SecretDraft): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(draft)) {
    if (value === undefined || value === '')
      continue
    if (value !== null && SECRET_MAP_KEYS.has(key)) {
      const map = parseHeaderLines(value)
      if (Object.keys(map).length > 0)
        out[key] = map
      continue
    }
    out[key] = value
  }
  return out
}

type Body = Record<string, unknown>

/** The body of `POST /monitors`: every field of the form plus the touched secrets. */
export function createBody(type: MonitorType, form: MonitorForm, draft: SecretDraft, includeChannels: boolean): Body {
  const body: Body = {
    type,
    name: form.name.trim(),
    description: form.description,
    active: form.active,
    interval_seconds: form.interval_seconds,
    timeout_seconds: form.timeout_seconds,
    retries: form.retries,
    retry_interval_seconds: form.retry_interval_seconds,
    degraded_threshold_ms: form.degraded_threshold_ms,
    inverted: form.inverted,
    parent_id: form.parent_id,
    config: form.config,
    tags: form.tags,
    alerting: form.alerting,
  }
  if (includeChannels)
    body.notification_channel_ids = form.notification_channel_ids
  const secrets = secretsPayload(draft)
  if (Object.keys(secrets).length > 0)
    body.secrets = secrets
  return body
}

const SCALAR_KEYS = [
  'name',
  'description',
  'active',
  'interval_seconds',
  'timeout_seconds',
  'retries',
  'retry_interval_seconds',
  'degraded_threshold_ms',
  'inverted',
  'parent_id',
  'tags',
  'notification_channel_ids',
] as const

/**
 * The body of `PATCH /monitors/{id}`: only top-level keys that changed. `config` and `alerting` carry
 * only their changed members, which the API merges into the stored values.
 */
export function patchBody(initial: MonitorForm, form: MonitorForm, draft: SecretDraft): Body {
  const body: Body = {}
  for (const key of SCALAR_KEYS) {
    if (!deepEqual(initial[key], form[key])) {
      body[key] = key === 'name'
        ? form.name.trim()
        : form[key]
    }
  }
  const config: Config = {}
  for (const [key, value] of Object.entries(form.config)) {
    if (!deepEqual(initial.config[key], value))
      config[key] = value
  }
  if (Object.keys(config).length > 0)
    body.config = config
  const alerting: Record<string, unknown> = {}
  for (const key of Object.keys(form.alerting) as Array<keyof MonitorForm['alerting']>) {
    if (!deepEqual(initial.alerting[key], form.alerting[key]))
      alerting[key] = form.alerting[key]
  }
  if (Object.keys(alerting).length > 0)
    body.alerting = alerting
  const secrets = secretsPayload(draft)
  if (Object.keys(secrets).length > 0)
    body.secrets = secrets
  return body
}
