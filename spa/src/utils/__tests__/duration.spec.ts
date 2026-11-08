import { describe, expect, it } from 'vitest'
import { formatDuration } from '../duration'

describe('formatDuration', () => {
  it('picks the largest unit that fits and keeps hours until two days', () => {
    expect(formatDuration(45_000, 'en')).toBe('45 seconds')
    expect(formatDuration(32 * 60_000, 'en')).toBe('32 minutes')
    expect(formatDuration(30 * 3_600_000, 'en')).toBe('30 hours')
    expect(formatDuration(3 * 86_400_000, 'en')).toBe('3 days')
    expect(formatDuration(-5_000, 'en')).toBe('0 seconds')
  })

  it('speaks the locale', () => {
    expect(formatDuration(32 * 60_000, 'hu')).toBe('32 perc')
  })
})
