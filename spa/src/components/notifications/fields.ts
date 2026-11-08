import type { ChannelType } from '../../api/types'

export type FieldKind = 'text' | 'url' | 'number' | 'select' | 'switch' | 'list' | 'map' | 'secret' | 'object'

/** One control of a channel form. `key` is the config key, or the secret key for `kind: 'secret'`. */
export interface FieldSpec {
  key: string
  kind: FieldKind
  /** Choices of a `select`. */
  options?: string[]
  required?: boolean
  /** A secret edited as several lines of text (a key list, `Name: value` headers). */
  multiline?: boolean
  /** Inclusive bounds of a `number`. */
  min?: number
  max?: number
  /** Members of an `object`, rendered as an optional group. */
  fields?: FieldSpec[]
  /** Suffix of the `channels.hints.*` message shown under the control. */
  hint?: string
  /** A secret that only has a control while the named config key holds a value. */
  requires?: string
  /**
   * A config value that decides where the secrets are sent. Changing it on a saved channel requires every
   * stored secret again (or its removal); mirrors the server's `destinationKeys()`.
   */
  destination?: true
}

const webhookUrl: FieldSpec = { key: 'webhook_url', kind: 'secret', required: true }

/** The smtp object of an e-mail channel; port and encryption fall back to the server defaults when left empty. */
export const SMTP_FIELDS: FieldSpec[] = [
  {
    key: 'host',
    kind: 'text',
    required: true,
    destination: true,
  },
  {
    key: 'port',
    kind: 'number',
    min: 1,
    max: 65535,
    destination: true,
  },
  {
    key: 'encryption',
    kind: 'select',
    options: ['starttls', 'tls', 'none'],
    destination: true,
  },
  { key: 'username', kind: 'text' },
  { key: 'from_address', kind: 'text', required: true },
  { key: 'from_name', kind: 'text' },
]

/** Field specs per channel type; they mirror the server's validation rules and the type catalogue. */
export const CHANNEL_FIELDS: Record<ChannelType, FieldSpec[]> = {
  email: [
    { key: 'to', kind: 'list', required: true },
    { key: 'smtp', kind: 'object', fields: SMTP_FIELDS },
    { key: 'smtp_password', kind: 'secret', requires: 'smtp' },
  ],
  webhook: [
    {
      key: 'url',
      kind: 'url',
      required: true,
      destination: true,
    },
    {
      key: 'method',
      kind: 'select',
      options: ['POST', 'PUT'],
      required: true,
    },
    {
      key: 'format',
      kind: 'select',
      options: ['json', 'form'],
      required: true,
    },
    { key: 'headers', kind: 'map' },
    { key: 'secret_headers', kind: 'secret', multiline: true },
    { key: 'signing_secret', kind: 'secret' },
  ],
  slack: [webhookUrl],
  discord: [
    { key: 'username', kind: 'text' },
    { key: 'thread_id', kind: 'text' },
    webhookUrl,
  ],
  teams: [webhookUrl],
  mattermost: [
    { key: 'channel', kind: 'text' },
    { key: 'username', kind: 'text' },
    webhookUrl,
  ],
  google_chat: [webhookUrl],
  telegram: [
    { key: 'chat_id', kind: 'text', required: true },
    { key: 'message_thread_id', kind: 'number', min: 1 },
    {
      key: 'server_url',
      kind: 'url',
      required: true,
      destination: true,
    },
    { key: 'silent', kind: 'switch' },
    { key: 'bot_token', kind: 'secret', required: true },
  ],
  ntfy: [
    {
      key: 'server_url',
      kind: 'url',
      required: true,
      destination: true,
    },
    { key: 'topic', kind: 'text', required: true },
    {
      key: 'priority',
      kind: 'number',
      min: 1,
      max: 5,
    },
    { key: 'access_token', kind: 'secret' },
  ],
  gotify: [
    {
      key: 'server_url',
      kind: 'url',
      required: true,
      destination: true,
    },
    {
      key: 'priority',
      kind: 'number',
      min: 0,
      max: 10,
    },
    { key: 'app_token', kind: 'secret', required: true },
  ],
  pushover: [
    {
      key: 'priority',
      kind: 'number',
      min: -2,
      max: 1,
    },
    { key: 'sound', kind: 'text' },
    { key: 'device', kind: 'text' },
    { key: 'user_key', kind: 'secret', required: true },
    { key: 'app_token', kind: 'secret', required: true },
  ],
  matrix: [
    {
      key: 'homeserver_url',
      kind: 'url',
      required: true,
      destination: true,
    },
    { key: 'room_id', kind: 'text', required: true },
    { key: 'access_token', kind: 'secret', required: true },
  ],
  pagerduty: [
    { key: 'routing_key', kind: 'secret', required: true },
  ],
  opsgenie: [
    {
      key: 'region',
      kind: 'select',
      options: ['us', 'eu'],
      required: true,
      destination: true,
    },
    {
      key: 'priority',
      kind: 'select',
      options: ['P1', 'P2', 'P3', 'P4', 'P5'],
    },
    { key: 'api_key', kind: 'secret', required: true },
  ],
  apprise: [
    {
      key: 'server_url',
      kind: 'url',
      required: true,
      destination: true,
    },
    { key: 'config_key', kind: 'text', hint: 'appriseKey' },
    {
      key: 'urls',
      kind: 'secret',
      multiline: true,
      hint: 'appriseUrls',
    },
  ],
}

/** Channel types in the order the picker offers them. */
export const CHANNEL_TYPES = Object.keys(CHANNEL_FIELDS) as ChannelType[]

/** Whether a config value counts as filled in. */
function present(value: unknown): boolean {
  return value !== null && value !== undefined && value !== ''
}

/** The secret specs whose controls are on screen for the type and config. */
export function renderedSecrets(type: ChannelType, config: Record<string, unknown>): FieldSpec[] {
  return CHANNEL_FIELDS[type].filter(f => f.kind === 'secret'
    && (f.requires === undefined || present(config[f.requires])))
}

/** The config specs of a type. */
export function configFields(type: ChannelType): FieldSpec[] {
  return CHANNEL_FIELDS[type].filter(f => f.kind !== 'secret')
}

/** A config value in the form the server compares: absent and null match, scalars compare as text. */
function comparable(value: unknown): unknown {
  if (value === null || value === undefined)
    return null
  return typeof value === 'object'
    ? JSON.stringify(value)
    : String(value)
}

function member(value: unknown, key: string): unknown {
  return typeof value === 'object' && value !== null
    ? (value as Record<string, unknown>)[key]
    : undefined
}

/** Dotted paths of the config values that decide where the secrets of a type are sent. */
export function destinationKeys(type: ChannelType): string[] {
  const keys: string[] = []
  for (const spec of CHANNEL_FIELDS[type]) {
    if (spec.destination)
      keys.push(spec.key)
    for (const sub of spec.fields ?? []) {
      if (sub.destination)
        keys.push(`${spec.key}.${sub.key}`)
    }
  }
  return keys
}

/** Whether a destination value differs between the two configs. */
export function destinationChanged(type: ChannelType, before: Record<string, unknown>, after: Record<string, unknown>): boolean {
  return destinationKeys(type).some((path) => {
    const [head = '', sub] = path.split('.')
    const a = sub === undefined
      ? before[head]
      : member(before[head], sub)
    const b = sub === undefined
      ? after[head]
      : member(after[head], sub)
    return comparable(a) !== comparable(b)
  })
}
