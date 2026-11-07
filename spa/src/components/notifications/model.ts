import type { ChannelCreateBody, ChannelSummary, ChannelType, ChannelTypeInfo, ChannelUpdateBody } from '../../api/types'
import type { SecretDraft } from '../monitors/forms/model'
import { clone, deepEqual, secretsPayload } from '../monitors/forms/model'
import { CHANNEL_FIELDS, configFields, renderedSecrets } from './fields'

export type ChannelConfig = Record<string, unknown>

/** The editable part of a channel; template fields are plain strings, blank meaning "use the built-in text". */
export interface ChannelForm {
  name: string
  active: boolean
  is_default: boolean
  /** Subscribe every existing monitor when the channel is the default; never stored. */
  apply_to_existing: boolean
  templates: { title: string, body: string }
  config: ChannelConfig
}

/** Maps arrive from the API as `[]` when empty; the editor works with objects. */
export function normaliseConfig(type: ChannelType, config: ChannelConfig): ChannelConfig {
  const out = clone(config)
  for (const spec of configFields(type)) {
    if (spec.kind === 'map' && (Array.isArray(out[spec.key]) || out[spec.key] === undefined))
      out[spec.key] = {}
  }
  return out
}

export function newChannelForm(info: ChannelTypeInfo): ChannelForm {
  return {
    name: '',
    active: true,
    is_default: false,
    apply_to_existing: false,
    templates: { title: '', body: '' },
    config: normaliseConfig(info.type, info.defaults),
  }
}

export function formFromChannel(channel: ChannelSummary): ChannelForm {
  return {
    name: channel.name,
    active: channel.active,
    is_default: channel.is_default,
    apply_to_existing: false,
    templates: {
      title: channel.templates?.title ?? '',
      body: channel.templates?.body ?? '',
    },
    config: normaliseConfig(channel.type, channel.config),
  }
}

/** Drops drafts of secrets whose control is not on screen. */
export function pruneSecrets(type: ChannelType, config: ChannelConfig, draft: SecretDraft): SecretDraft {
  const allowed = new Set(renderedSecrets(type, config).map(f => f.key))
  return Object.fromEntries(Object.entries(draft).filter(([key]) => allowed.has(key)))
}

/**
 * Turning the custom SMTP server off also removes its stored password, so no orphaned secret stays behind.
 * Only applies when the server was on at load time and the password is stored.
 */
export function withSmtpSecretCleared(type: ChannelType, initial: ChannelConfig, config: ChannelConfig, storedKeys: string[], draft: SecretDraft): SecretDraft {
  const wasOn = initial.smtp !== null && initial.smtp !== undefined
  const isOff = config.smtp === null || config.smtp === undefined
  if (type !== 'email' || !wasOn || !isOff || !storedKeys.includes('smtp_password'))
    return draft
  return { ...draft, smtp_password: null }
}

/** Whether any secret has been touched. */
export function hasSecretDraft(draft: SecretDraft): boolean {
  return Object.values(draft).some(v => v !== undefined && v !== '')
}

function templatesBody(form: ChannelForm): { title: string | null, body: string | null } {
  return {
    title: form.templates.title.trim() === ''
      ? null
      : form.templates.title,
    body: form.templates.body.trim() === ''
      ? null
      : form.templates.body,
  }
}

/** The body of `POST /notification-channels` and of the unsaved test send. */
export function createBody(type: ChannelType, form: ChannelForm, draft: SecretDraft): ChannelCreateBody {
  const config: ChannelConfig = {}
  for (const [key, value] of Object.entries(form.config)) {
    const spec = CHANNEL_FIELDS[type].find(f => f.key === key)
    // An empty map is the server default; it is only sent on update, to clear stored entries.
    if (spec?.kind === 'map' && Object.keys(value as object).length === 0)
      continue
    config[key] = value
  }
  const body: ChannelCreateBody = {
    type,
    name: form.name.trim(),
    active: form.active,
    is_default: form.is_default,
    config,
  }
  const templates = templatesBody(form)
  if (templates.title !== null || templates.body !== null)
    body.templates = templates
  if (form.is_default && form.apply_to_existing)
    body.apply_to_existing = true
  const secrets = secretsPayload(draft)
  if (Object.keys(secrets).length > 0)
    body.secrets = secrets
  return body
}

/** The body of `PATCH /notification-channels/{id}`: only what changed; the API merges `config` over the stored one. */
export function patchBody(initial: ChannelForm, form: ChannelForm, draft: SecretDraft): ChannelUpdateBody {
  const body: ChannelUpdateBody = {}
  if (initial.name !== form.name)
    body.name = form.name.trim()
  if (initial.active !== form.active)
    body.active = form.active
  if (initial.is_default !== form.is_default)
    body.is_default = form.is_default
  if (form.is_default && form.apply_to_existing)
    body.apply_to_existing = true
  if (!deepEqual(initial.templates, form.templates))
    body.templates = templatesBody(form)
  const config: ChannelConfig = {}
  for (const [key, value] of Object.entries(form.config)) {
    if (!deepEqual(initial.config[key], value))
      config[key] = value
  }
  if (Object.keys(config).length > 0)
    body.config = config
  const secrets = secretsPayload(draft)
  if (Object.keys(secrets).length > 0)
    body.secrets = secrets
  return body
}
