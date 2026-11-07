import type { Maintenance, MaintenanceCreateBody, MaintenanceStrategy, MaintenanceUpdateBody } from '../../api/types'
import { fromZonedInput, toZonedInput } from '../../utils/zonedTime'
import { deepEqual } from '../monitors/forms/model'

export const STRATEGIES: MaintenanceStrategy[] = [
  'once',
  'interval',
  'weekly',
  'monthly',
  'cron',
]

/** The editable part of a maintenance. Dates and times are wall-clock values in `timezone`. */
export interface MaintenanceForm {
  title: string
  description: string
  strategy: MaintenanceStrategy
  timezone: string
  active: boolean
  /** `YYYY-MM-DDTHH:mm`, once-only. */
  starts_at: string
  ends_at: string
  /** `HH:mm`. */
  start_time: string
  duration_minutes: number | null
  every_days: number | null
  /** 1 (Monday) to 7 (Sunday). */
  weekdays: number[]
  /** 1 to 31, or -1 for the last day. */
  month_days: number[]
  cron: string
  /** `YYYY-MM-DD`. */
  valid_from: string
  valid_until: string
  monitor_ids: number[]
  status_page_ids: number[]
}

type StrategyKey = 'starts_at' | 'ends_at' | 'start_time' | 'duration_minutes' | 'every_days' | 'weekdays' | 'month_days' | 'cron' | 'valid_from' | 'valid_until'

/** The fields each strategy uses; the server clears the others. */
export const STRATEGY_FIELDS: Record<MaintenanceStrategy, StrategyKey[]> = {
  once: ['starts_at', 'ends_at'],
  interval: [
    'start_time',
    'duration_minutes',
    'every_days',
    'valid_from',
    'valid_until',
  ],
  weekly: [
    'start_time',
    'duration_minutes',
    'weekdays',
    'valid_from',
    'valid_until',
  ],
  monthly: [
    'start_time',
    'duration_minutes',
    'month_days',
    'valid_from',
    'valid_until',
  ],
  cron: [
    'cron',
    'duration_minutes',
    'valid_from',
    'valid_until',
  ],
}

export function newMaintenanceForm(timezone: string): MaintenanceForm {
  return {
    title: '',
    description: '',
    strategy: 'once',
    timezone,
    active: true,
    starts_at: '',
    ends_at: '',
    start_time: '02:00',
    duration_minutes: 60,
    every_days: 7,
    weekdays: [],
    month_days: [],
    cron: '',
    valid_from: '',
    valid_until: '',
    monitor_ids: [],
    status_page_ids: [],
  }
}

export function formFromMaintenance(m: Maintenance, workspaceTimezone: string): MaintenanceForm {
  const timezone = m.timezone ?? workspaceTimezone
  return {
    title: m.title,
    description: m.description ?? '',
    strategy: m.strategy,
    timezone,
    active: m.active,
    starts_at: toZonedInput(m.starts_at, timezone),
    ends_at: toZonedInput(m.ends_at, timezone),
    start_time: m.start_time?.slice(0, 5) ?? '',
    duration_minutes: m.duration_minutes,
    every_days: m.every_days,
    weekdays: [
      ...(m.weekdays ?? []),
    ],
    month_days: [
      ...(m.month_days ?? []),
    ],
    cron: m.cron ?? '',
    valid_from: m.valid_from?.slice(0, 10) ?? '',
    valid_until: m.valid_until?.slice(0, 10) ?? '',
    monitor_ids: [...m.monitor_ids],
    status_page_ids: [...m.status_page_ids],
  }
}

/** A number typed into a field: blank or malformed input is "no value". */
export function toInt(value: unknown): number | null {
  if (value === null || value === undefined || String(value).trim() === '')
    return null
  const n = Number(value)
  return Number.isInteger(n)
    ? n
    : null
}

function blankToNull(value: string): string | null {
  return value.trim() === ''
    ? null
    : value
}

function strategyValue(form: MaintenanceForm, key: StrategyKey): MaintenanceCreateBody[StrategyKey] {
  switch (key) {
    case 'starts_at':
      return fromZonedInput(form.starts_at, form.timezone)
    case 'ends_at':
      return fromZonedInput(form.ends_at, form.timezone)
    case 'start_time':
      return blankToNull(form.start_time)
    case 'cron':
      return blankToNull(form.cron)
    case 'valid_from':
      return blankToNull(form.valid_from)
    case 'valid_until':
      return blankToNull(form.valid_until)
    case 'duration_minutes':
      return form.duration_minutes
    case 'every_days':
      return form.every_days
    case 'weekdays':
      return [...form.weekdays].sort((a, b) => a - b)
    case 'month_days':
      return [...form.month_days].sort((a, b) => a - b)
  }
}

/** The body of `POST /maintenances`: the common fields plus those of the chosen strategy. */
export function createBody(form: MaintenanceForm): MaintenanceCreateBody {
  const body: MaintenanceCreateBody = {
    title: form.title,
    description: blankToNull(form.description),
    strategy: form.strategy,
    timezone: form.timezone,
    active: form.active,
    monitor_ids: [...form.monitor_ids].sort((a, b) => a - b),
    status_page_ids: [...form.status_page_ids].sort((a, b) => a - b),
  }
  const target: Record<string, unknown> = body
  for (const key of STRATEGY_FIELDS[form.strategy])
    target[key] = strategyValue(form, key)
  return body
}

/** The body of `PATCH /maintenances/{id}`: only what differs from the loaded form. Changing the strategy sends all of its fields. */
export function patchBody(initial: MaintenanceForm, current: MaintenanceForm): MaintenanceUpdateBody {
  const before: Record<string, unknown> = createBody(initial)
  const after: Record<string, unknown> = createBody(current)
  const strategyChanged = initial.strategy !== current.strategy
  const out: MaintenanceUpdateBody = {}
  const target: Record<string, unknown> = out
  for (const [key, value] of Object.entries(after)) {
    if (strategyChanged || !deepEqual(value, before[key]))
      target[key] = value
  }
  return out
}

/** IANA zone names for the picker; the current value is always offered. */
export function timezoneOptions(current: string): string[] {
  const supported = (Intl as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? []
  const all = new Set<string>(['UTC', ...supported])
  if (current !== '')
    all.add(current)
  return [...all].sort()
}
