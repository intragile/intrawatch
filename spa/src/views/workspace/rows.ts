import type { paths } from '../../api/schema'

type Body<P extends keyof paths> = paths[P] extends { get: { responses: { 200: { content: { 'application/json': infer B } } } } } ? B : never
type Item<B> = B extends { data: Array<infer I> } ? I : never

/** The token and audit list items are untyped in the generated schema; these narrow them at runtime. */
export type RawToken = Item<Body<'/workspaces/{workspace}/tokens'>>
export type RawAuditEvent = Item<Body<'/workspaces/{workspace}/audit'>>

export interface TokenRow {
  id: string
  name: string
  abilities: string[]
  lastUsedAt: string | null
  expiresAt: string | null
  createdAt: string | null
}

export interface AuditRow {
  id: number
  action: string
  actorType: string
  actorId: number | null
  actorUserId: number | null
  subjectType: string | null
  subjectId: number | null
  changes: Array<{ field: string, from: unknown, to: unknown }>
  ip: string | null
  createdAt: string
}

function text(v: unknown): string | null {
  return typeof v === 'string' && v !== ''
    ? v
    : null
}
function num(v: unknown): number | null {
  return typeof v === 'number'
    ? v
    : null
}

export function parseToken(raw: RawToken): TokenRow {
  return {
    id: String(raw.id),
    name: text(raw.name) ?? '',
    abilities: Array.isArray(raw.abilities)
      ? raw.abilities.filter((a): a is string => typeof a === 'string')
      : [],
    lastUsedAt: text(raw.last_used_at),
    expiresAt: text(raw.expires_at),
    createdAt: text(raw.created_at),
  }
}

/** Audit `changes` map a field to `[old, new]`; anything else is shown as a single value. */
function parseChanges(raw: unknown): AuditRow['changes'] {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw))
    return []
  return Object.entries(raw).map(([field, value]) => (Array.isArray(value) && value.length === 2
    ? { field, from: value[0], to: value[1] }
    : { field, from: undefined, to: value }))
}

export function parseAuditEvent(raw: RawAuditEvent): AuditRow {
  return {
    id: num(raw.id) ?? 0,
    action: text(raw.action) ?? '',
    actorType: text(raw.actor_type) ?? 'system',
    actorId: num(raw.actor_id),
    actorUserId: num(raw.actor_user_id),
    subjectType: text(raw.subject_type),
    subjectId: num(raw.subject_id),
    changes: parseChanges(raw.changes),
    ip: text(raw.ip),
    createdAt: text(raw.created_at) ?? '',
  }
}

export function formatChange(value: unknown): string {
  if (value === undefined)
    return ''
  if (value === null)
    return 'null'
  return typeof value === 'string'
    ? value
    : JSON.stringify(value)
}
