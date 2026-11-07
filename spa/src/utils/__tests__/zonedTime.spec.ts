import { describe, expect, it } from 'vitest'
import { fromZonedInput, toZonedInput } from '../zonedTime'

describe('zoned time inputs', () => {
  it('shows an instant as the wall clock of the zone', () => {
    expect(toZonedInput('2026-07-01T10:30:00Z', 'Europe/Budapest')).toBe('2026-07-01T12:30')
    expect(toZonedInput('2026-01-01T10:30:00Z', 'Europe/Budapest')).toBe('2026-01-01T11:30')
  })

  it('turns a wall clock back into the same instant', () => {
    expect(fromZonedInput('2026-07-01T12:30', 'Europe/Budapest')).toBe('2026-07-01T10:30:00.000Z')
    expect(fromZonedInput('2026-01-01T11:30', 'Europe/Budapest')).toBe('2026-01-01T10:30:00.000Z')
    expect(fromZonedInput('2026-03-10T08:00', 'America/New_York')).toBe('2026-03-10T12:00:00.000Z')
  })

  it('returns nothing for empty or invalid values', () => {
    expect(toZonedInput(null, 'UTC')).toBe('')
    expect(fromZonedInput('', 'UTC')).toBeNull()
    expect(fromZonedInput('2026-01-01T10:00', 'Mars/Base')).toBeNull()
  })
})
