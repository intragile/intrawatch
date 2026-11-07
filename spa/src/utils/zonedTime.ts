/** Conversion between an instant and the wall-clock value of a `datetime-local` input in a given IANA zone. */

const LOCAL_PATTERN = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/

function wallParts(instant: number, zone: string): Record<string, string> {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: zone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
  const out: Record<string, string> = {}
  for (const part of formatter.formatToParts(new Date(instant)))
    out[part.type] = part.value
  return out
}

/** Offset of the zone from UTC at the instant, in milliseconds. */
function offsetAt(instant: number, zone: string): number {
  const p = wallParts(instant, zone)
  const asUtc = Date.UTC(
    Number(p.year),
    Number(p.month) - 1,
    Number(p.day),
    Number(p.hour),
    Number(p.minute),
    Number(p.second),
  )
  return asUtc - Math.floor(instant / 1000) * 1000
}

export function isValidZone(zone: string): boolean {
  try {
    return new Intl.DateTimeFormat('en', { timeZone: zone }).resolvedOptions().timeZone !== ''
  }
  catch {
    return false
  }
}

/** `YYYY-MM-DDTHH:mm` as the wall clock of the zone shows the instant; empty when there is none. */
export function toZonedInput(iso: string | null | undefined, zone: string): string {
  if (!iso)
    return ''
  const instant = new Date(iso).getTime()
  if (Number.isNaN(instant) || !isValidZone(zone))
    return ''
  const p = wallParts(instant, zone)
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}

/** The UTC instant (ISO 8601) at which the zone's wall clock shows the value; null when it is empty or invalid. */
export function fromZonedInput(local: string | null | undefined, zone: string): string | null {
  const match = local
    ? LOCAL_PATTERN.exec(local)
    : null
  if (!match || !isValidZone(zone))
    return null
  const [, y, mo, d, h, mi] = match
  const wall = Date.UTC(Number(y), Number(mo) - 1, Number(d), Number(h), Number(mi))
  // The offset depends on the instant itself, so it is refined once around a DST change.
  const first = wall - offsetAt(wall, zone)
  const instant = wall - offsetAt(first, zone)
  return new Date(instant).toISOString()
}
