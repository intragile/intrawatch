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

const DAY = 86_400

/**
 * Whole-number formatting for the chart's value axis; an unknown locale falls back to the runtime default.
 */
export function wholeNumberFormatter(locale: string): Intl.NumberFormat {
  try {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 })
  }
  catch {
    return new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 })
  }
}

/**
 * Tick labels for the chart's time axis in the interface language and the user's time zone; uPlot's own
 * labels are hard-wired to US month/day order and a 12-hour clock. The tick spacing picks the precision:
 * clock time below a day, month and day below a month, month and year beyond. Clock ticks get the date as
 * a second line on the first tick and wherever the day changes.
 *
 * @returns A function of the tick positions (epoch seconds) and their spacing (seconds).
 */
export function timeTickFormatter(locale: string, timeZone: string): (ticks: number[], spacing: number) => string[] {
  // An unknown time zone falls back to UTC, an unknown locale to the runtime default: a bad preference must
  // not keep the chart from rendering.
  const attempts: [string | undefined, string][] = [
    [locale, timeZone],
    [locale, 'UTC'],
    [undefined, 'UTC'],
  ]
  const make = (options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat => {
    for (const [tag, zone] of attempts) {
      try {
        return new Intl.DateTimeFormat(tag, { ...options, timeZone: zone })
      }
      catch {}
    }
    return new Intl.DateTimeFormat(undefined, options)
  }
  const clock = make({ hour: 'numeric', minute: '2-digit' })
  const clockWithSeconds = make({ hour: 'numeric', minute: '2-digit', second: '2-digit' })
  const day = make({ month: 'short', day: 'numeric' })
  const month = make({ year: 'numeric', month: 'short' })
  const dayKey = make({ year: 'numeric', month: '2-digit', day: '2-digit' })

  return (ticks, spacing) => {
    if (spacing >= 28 * DAY)
      return ticks.map(t => month.format(t * 1000))
    if (spacing >= DAY)
      return ticks.map(t => day.format(t * 1000))
    const time = spacing < 60
      ? clockWithSeconds
      : clock
    let previous = ''
    return ticks.map((t) => {
      const at = t * 1000
      const key = dayKey.format(at)
      const label = key === previous
        ? time.format(at)
        : `${time.format(at)}\n${day.format(at)}`
      previous = key
      return label
    })
  }
}
