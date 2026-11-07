import type { WorkspaceSummary } from '../api/types'
import type { SessionUser } from '../stores/session'

type Can = WorkspaceSummary['can']

export function abilities(value: boolean, overrides: Partial<Can> = {}): Can {
  return {
    view: value,
    update: value,
    delete: value,
    viewMembers: value,
    manageMembers: value,
    viewAudit: value,
    viewMonitors: value,
    manageMonitors: value,
    viewNotifications: value,
    manageNotifications: value,
    viewIncidents: value,
    manageIncidents: value,
    viewStatusPages: value,
    manageStatusPages: value,
    manageCustomDomains: value,
    ...overrides,
  }
}

export const viewerCan: Can = abilities(false, {
  view: true,
  viewMembers: true,
  viewMonitors: true,
  viewNotifications: true,
  viewIncidents: true,
  viewStatusPages: true,
})

export function workspace(overrides: Partial<WorkspaceSummary> = {}): WorkspaceSummary {
  return {
    id: '01JAAAAAAAAAAAAAAAAAAAAAAA',
    name: 'Acme',
    slug: 'acme',
    timezone: 'Europe/Budapest',
    role: 'owner',
    channel: 'workspace.1',
    can: abilities(true),
    ...overrides,
  }
}

export function me(overrides: Partial<SessionUser> = {}): SessionUser {
  return {
    id: 1,
    name: 'Ada Lovelace',
    email: 'ada@example.com',
    two_factor_enabled: false,
    workspaces: [
      workspace(),
    ],
    ...overrides,
  }
}
