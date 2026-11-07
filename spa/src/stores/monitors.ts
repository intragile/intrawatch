import type { Monitor, MonitorStatus } from '../api/types'
import type { ChecksEvent, StatusChangedEvent } from '../live/events'
import { defineStore } from 'pinia'
import { ref } from 'vue'

/** Heartbeats kept per monitor for the mini bar. */
export const MAX_BEATS = 50

/** Upper bound on how long queued events wait when animation frames are paused (hidden tab). */
const FLUSH_FALLBACK_MS = 1000

export interface Beat {
  status: MonitorStatus
  latency_ms: number | null
  at: string
}

/**
 * Monitors loaded on screen, keyed by id, patched by live events. Events are queued and applied once
 * per animation frame, only to monitors that are loaded; events for any other id are dropped.
 */
export const useMonitors = defineStore('monitors', () => {
  const items = ref(new Map<number, Monitor>())
  const beats = ref(new Map<number, Beat[]>())

  let statusQueue: StatusChangedEvent[] = []
  let checksQueue: ChecksEvent[] = []
  let frame: number | null = null
  let fallback: ReturnType<typeof setTimeout> | null = null

  function upsert(monitor: Monitor): void {
    items.value.set(monitor.id, monitor)
  }

  function remove(id: number): void {
    items.value.delete(id)
    beats.value.delete(id)
  }

  function clear(): void {
    items.value.clear()
    beats.value.clear()
    statusQueue = []
    checksQueue = []
  }

  function flush(): void {
    if (frame !== null && typeof cancelAnimationFrame === 'function')
      cancelAnimationFrame(frame)
    if (fallback !== null)
      clearTimeout(fallback)
    frame = null
    fallback = null

    const statuses = statusQueue
    const checks = checksQueue
    statusQueue = []
    checksQueue = []

    const loaded = items.value
    for (const e of statuses) {
      const monitor = loaded.get(e.monitor_id)
      if (!monitor)
        continue
      monitor.state.status = e.to
      monitor.state.since = e.at
      monitor.state.last_message = e.message
    }

    const incoming = new Map<number, Beat[]>()
    for (const batch of checks) {
      for (const c of batch.checks) {
        const monitor = loaded.get(c.monitor_id)
        if (!monitor)
          continue
        monitor.state.last_checked_at = c.checked_at
        monitor.state.last_latency_ms = c.latency_ms
        let list = incoming.get(c.monitor_id)
        if (!list) {
          list = []
          incoming.set(c.monitor_id, list)
        }
        list.push({ status: c.status, latency_ms: c.latency_ms, at: c.checked_at })
      }
    }
    // One array replacement per monitor keeps the reactive work independent of the burst size.
    for (const [id, added] of incoming) {
      beats.value.set(id, [
        ...(beats.value.get(id) ?? []),
        ...added,
      ].slice(-MAX_BEATS))
    }
  }

  function schedule(): void {
    if (frame !== null || fallback !== null)
      return
    frame = typeof requestAnimationFrame === 'function'
      ? requestAnimationFrame(flush)
      : -1
    fallback = setTimeout(flush, frame === -1
      ? 16
      : FLUSH_FALLBACK_MS)
  }

  function applyStatus(e: StatusChangedEvent): void {
    statusQueue.push(e)
    schedule()
  }

  function applyChecks(e: ChecksEvent): void {
    checksQueue.push(e)
    schedule()
  }

  return {
    items,
    beats,
    upsert,
    remove,
    clear,
    applyStatus,
    applyChecks,
  }
})
