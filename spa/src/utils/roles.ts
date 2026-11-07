import type { Schemas } from '../api/types'

export type WorkspaceRole = Schemas['WorkspaceRole']

// Keyed by the generated union, so a role added to the API fails type-checking here.
const ROLE_KEYS: Record<WorkspaceRole, true> = {
  owner: true,
  admin: true,
  editor: true,
  viewer: true,
}

export const ROLES = Object.keys(ROLE_KEYS) as WorkspaceRole[]
