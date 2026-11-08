import type { Monitor, WorkspaceSummary } from '../api/types'
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
    can_create_workspace: true,
    workspaces: [
      workspace(),
    ],
    ...overrides,
  }
}

/** A `/me` payload listing the given workspaces (the first one is the current one in most tests). */
export function meWith(...workspaces: Array<Parameters<typeof workspace>[0]>): SessionUser {
  return me({
    workspaces: workspaces.map(w => workspace(w)),
  })
}

export function monitor(overrides: Partial<Monitor> = {}): Monitor {
  return {
    id: 1,
    name: 'Homepage',
    description: null,
    type: 'http',
    active: true,
    interval_seconds: 60,
    timeout_seconds: 10,
    retries: 0,
    retry_interval_seconds: 60,
    degraded_threshold_ms: null,
    inverted: false,
    parent_id: null,
    config: {},
    secret_keys: [],
    notification_channel_ids: [],
    alerting: {
      resend_interval_minutes: 0,
      notify_degraded: false,
      degraded_alert_after_seconds: 0,
      notify_changes: false,
    },
    tags: [],
    push_url: null,
    has_push_token: false,
    state: {
      status: 'up',
      since: '2025-12-31T00:00:00Z',
      last_checked_at: null,
      last_latency_ms: null,
      last_message: null,
    },
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}
