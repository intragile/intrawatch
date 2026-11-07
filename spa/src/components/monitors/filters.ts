import type { MonitorStatus, MonitorType } from '../../api/types'

export interface MonitorFilterValues {
  type: MonitorType | null
  status: MonitorStatus | null
  tag: number | null
  q: string
  active: 'true' | 'false' | null
}

/** Quiet period before a typed search is applied. */
export const SEARCH_DEBOUNCE_MS = 300

/** Most monitors one bulk request may touch (the API rejects more). */
export const BULK_MAX_IDS = 500
