import type { Incident, IncidentImpact, IncidentStatus, IncidentTimelineEntry } from '../../api/types'

export const INCIDENT_STATUSES: IncidentStatus[] = [
  'investigating',
  'identified',
  'monitoring',
  'resolved',
]
export const INCIDENT_IMPACTS: IncidentImpact[] = ['none', 'minor', 'major', 'critical']

/** The timeline of a fetched incident, newest first as the API sends it. */
export function timelineOf(incident: Pick<Incident, 'updates'>): IncidentTimelineEntry[] {
  return incident.updates ?? []
}
