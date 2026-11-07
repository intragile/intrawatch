import type { MonitorRollup } from '../../api/types'

export type RangeKey = '24h' | '7d' | '30d' | '90d'

type RollupBucket = 'minute' | 'hour' | 'day'

/** Chart ranges with the rollup bucket the API documents for each. */
export const RANGES: ReadonlyArray<{ key: RangeKey, bucket: RollupBucket, hours: number }> = [
  { key: '24h', bucket: 'minute', hours: 24 },
  { key: '7d', bucket: 'hour', hours: 24 * 7 },
  { key: '30d', bucket: 'day', hours: 24 * 30 },
  { key: '90d', bucket: 'day', hours: 24 * 90 },
]

/** One plotted latency sample; `t` is in epoch seconds. */
export interface ChartPoint {
  t: number
  avg: number | null
  min: number | null
  max: number | null
}

export interface ChartSummary {
  count: number
  min: number | null
  avg: number | null
  max: number | null
}

export function pointsFromRollups(rollups: MonitorRollup[]): ChartPoint[] {
  return rollups.map(r => ({
    t: Math.floor(Date.parse(r.bucket_start) / 1000),
    avg: r.latency_avg,
    min: r.latency_min,
    max: r.latency_max,
  }))
}

/** Appends live samples that are newer than the last rollup bucket. */
export function withLive(points: ChartPoint[], live: ChartPoint[]): ChartPoint[] {
  const last = points.at(-1)?.t ?? Number.NEGATIVE_INFINITY
  return [
    ...points,
    ...live.filter(p => p.t > last),
  ]
}

/** Column-oriented data in the shape uPlot expects. */
export function alignedData(points: ChartPoint[]): [number[], Array<number | null>] {
  return [
    points.map(p => p.t),
    points.map(p => p.avg),
  ]
}

export function summarize(points: ChartPoint[]): ChartSummary {
  const avgs = points.map(p => p.avg).filter((v): v is number => v !== null)
  const mins = points.map(p => p.min ?? p.avg).filter((v): v is number => v !== null)
  const maxs = points.map(p => p.max ?? p.avg).filter((v): v is number => v !== null)
  return {
    count: avgs.length,
    min: mins.length > 0
      ? Math.min(...mins)
      : null,
    max: maxs.length > 0
      ? Math.max(...maxs)
      : null,
    avg: avgs.length > 0
      ? Math.round(avgs.reduce((a, b) => a + b, 0) / avgs.length)
      : null,
  }
}

/**
 * Availability as a fraction: (up + degraded + pending) / (total - maintenance).
 * Null when there is nothing to measure.
 */
export function availability(rollups: MonitorRollup[]): number | null {
  let good = 0
  let counted = 0
  for (const r of rollups) {
    good += r.up + r.degraded + r.pending
    counted += r.total - r.maintenance
  }
  return counted > 0
    ? good / counted
    : null
}
