const UNITS: Array<['day' | 'hour' | 'minute' | 'second', number]> = [
  ['day', 86_400],
  ['hour', 3_600],
  ['minute', 60],
  ['second', 1],
]

/**
 * How long something has lasted, in the largest unit that fits ("32 minutes", "3 days").
 * Hours switch to days only after two days, so a long night still reads in hours.
 */
export function formatDuration(ms: number, locale: string): string {
  const seconds = Math.max(0, Math.floor(ms / 1000))
  for (const [unit, size] of UNITS) {
    const threshold = unit === 'day'
      ? 2 * size
      : size
    if (seconds >= threshold || unit === 'second') {
      const value = Math.floor(seconds / size)
      try {
        return new Intl.NumberFormat(locale, { style: 'unit', unit, unitDisplay: 'long' }).format(value)
      }
      catch {
        return `${value} ${unit}`
      }
    }
  }
  return ''
}
