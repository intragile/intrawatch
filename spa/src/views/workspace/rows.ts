import type { AuditEntry, TokenItem } from '../../api/types'

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
  subjectId: string | null
  changes: Array<{ field: string, from: unknown, to: unknown }>
  ip: string | null
  createdAt: string
}

export function parseToken(raw: TokenItem): TokenRow {
  return {
    id: String(raw.id),
    name: raw.name,
    abilities: raw.abilities ?? [],
    lastUsedAt: raw.last_used_at,
    expiresAt: raw.expires_at,
    createdAt: raw.created_at,
  }
}

/**
 * An audit entry as the client returns it: `openapi-fetch` widens the `[old, new]` tuples of `changes`
 * to arrays.
 */
type AuditPayload = Omit<AuditEntry, 'changes'> & { changes: Record<string, readonly unknown[]> | null }

/** Audit `changes` map each field to its `[old, new]` pair. */
function parseChanges(raw: AuditPayload['changes']): AuditRow['changes'] {
  return Object.entries(raw ?? {}).map(([field, [from, to]]) => ({ field, from, to }))
}

export function parseAuditEvent(raw: AuditPayload): AuditRow {
  return {
    id: raw.id,
    action: raw.action,
    actorType: raw.actor_type,
    actorId: raw.actor_id,
    actorUserId: raw.actor_user_id,
    subjectType: raw.subject_type,
    subjectId: raw.subject_id,
    changes: parseChanges(raw.changes),
    ip: raw.ip,
    createdAt: raw.created_at,
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
