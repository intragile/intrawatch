import type { InjectionKey, Ref } from 'vue'
import type { Monitor, MonitorStatus } from '../../api/types'

/** What the monitor list shares with the overview shown beside it while no monitor is open. */
export interface MonitorOverviewData {
  counts: Ref<Map<MonitorStatus, number>>
  total: Ref<number>
  monitors: Ref<Monitor[]>
  now: Ref<number>
}

export const MONITOR_OVERVIEW: InjectionKey<MonitorOverviewData> = Symbol('monitor-overview')
