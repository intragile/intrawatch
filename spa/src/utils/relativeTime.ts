const UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
  ['day', 86_400],
  ['hour', 3_600],
  ['minute', 60],
]

/** Formats an ISO timestamp relative to `now` (epoch ms), e.g. "5 minutes ago". */
export function relativeTime(iso: string, now: number, locale: string): string {
  const time = new Date(iso).getTime()
  if (Number.isNaN(time))
    return iso
  const seconds = Math.round((time - now) / 1000)
  const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
  const abs = Math.abs(seconds)
  for (const [unit, size] of UNITS) {
    if (abs >= size)
      return format.format(Math.trunc(seconds / size), unit)
  }
  return format.format(seconds === 0
    ? 0
    : Math.trunc(seconds / 10) * 10, 'second')
}
