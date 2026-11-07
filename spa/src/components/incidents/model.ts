import type { Incident, IncidentImpact, IncidentStatus, IncidentTimelineEntry } from '../../api/types'

export const INCIDENT_STATUSES: IncidentStatus[] = [
  'investigating',
  'identified',
  'monitoring',
  'resolved',
]
export const INCIDENT_IMPACTS: IncidentImpact[] = ['none', 'minor', 'major', 'critical']

function isEntry(value: unknown): value is IncidentTimelineEntry {
  if (typeof value !== 'object' || value === null)
    return false
  const v = value as Record<string, unknown>
  return typeof v.id === 'number'
    && typeof v.message === 'string'
    && typeof v.created_at === 'string'
    && INCIDENT_STATUSES.includes(v.status as IncidentStatus)
}

/**
 * The timeline of a fetched incident, newest first as the API sends it. The API reference describes
 * `updates` loosely, so each entry is checked against the documented timeline entry shape.
 */
export function timelineOf(incident: Pick<Incident, 'updates'>): IncidentTimelineEntry[] {
  const raw: unknown[] = incident.updates
  return raw.filter(isEntry)
}
