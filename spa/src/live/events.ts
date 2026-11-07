import type { MonitorStatus } from '../api/types'

/** Payload of the `.monitor.status_changed` broadcast. */
export interface StatusChangedEvent {
  monitor_id: number
  from: MonitorStatus | null
  to: MonitorStatus
  at: string
  previous_since: string | null
  message: string | null
}

/** One executed check inside a `.monitor.checks` broadcast. */
export interface CheckEvent {
  monitor_id: number
  status: MonitorStatus
  latency_ms: number | null
  checked_at: string
}

/** Payload of the `.monitor.checks` broadcast. */
export interface ChecksEvent {
  checks: CheckEvent[]
}

/** Payload of the `.alert.raised` broadcast. */
export interface AlertRaisedEvent {
  alert_event_ids: number[]
}

/** Payload of the `.monitor.changed` broadcast. */
export interface MonitorChangedEvent {
  monitor_id: number
  key: string
  old: unknown
  new: unknown
  at: string
}

export interface LiveEvents {
  status_changed: StatusChangedEvent
  checks: ChecksEvent
  alert: AlertRaisedEvent
}
