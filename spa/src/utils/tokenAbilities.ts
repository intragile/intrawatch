import type { Schemas } from '../api/types'

export type TokenAbility = Schemas['TokenAbility']

/**
 * Message key suffix (`tokens.ability.<key>`) per ability. Typed against the generated
 * union: a missing or unknown ability fails type-checking, and the list below follows it.
 */
export const ABILITY_LABEL_KEYS: Record<TokenAbility, string> = {
  'workspace:read': 'workspaceRead',
  'workspace:manage': 'workspaceManage',
  'members:manage': 'membersManage',
  'audit:read': 'auditRead',
  'monitors:read': 'monitorsRead',
  'monitors:write': 'monitorsWrite',
  'notifications:read': 'notificationsRead',
  'notifications:write': 'notificationsWrite',
  'incidents:read': 'incidentsRead',
  'incidents:write': 'incidentsWrite',
  'status-pages:read': 'statusPagesRead',
  'status-pages:write': 'statusPagesWrite',
}

export const TOKEN_ABILITIES = Object.keys(ABILITY_LABEL_KEYS) as TokenAbility[]
