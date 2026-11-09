import { describe, expect, it } from 'vitest'
import { timeTickFormatter, wholeNumberFormatter } from '../chartData'

const HOUR = 3600
const DAY = 24 * HOUR
/** 2026-11-08 22:00:00 UTC, which is 23:00 on the 8th in Budapest. */
const START = Date.UTC(2026, 10, 8, 22) / 1000

describe('timeTickFormatter', () => {
  it('labels clock ticks on a 24-hour clock in Hungarian, with the date where the day changes', () => {
    const format = timeTickFormatter('hu', 'Europe/Budapest')

    expect(format([START, START + HOUR, START + 2 * HOUR], HOUR)).toEqual([
      '23:00\nnov. 8.',
      '0:00\nnov. 9.',
      '1:00',
    ])
  })

  it('follows the time zone it is given', () => {
    const format = timeTickFormatter('hu', 'UTC')

    expect(format([START, START + HOUR], HOUR)).toEqual(['22:00\nnov. 8.', '23:00'])
  })

  it('uses English month names and day-month order for English, never the numeric US date', () => {
    const format = timeTickFormatter('en', 'UTC')

    const [first, second] = format([START, START + HOUR], HOUR)
    expect(first).toMatch(/^10:00\sPM\nNov 8$/)
    expect(second).toMatch(/^11:00\sPM$/)
    expect(format([START], DAY)).toEqual(['Nov 8'])
  })

  it('drops the clock for day ticks and the day for month ticks', () => {
    const format = timeTickFormatter('hu', 'Europe/Budapest')

    expect(format([START + 2 * HOUR, START + DAY + 2 * HOUR], DAY)).toEqual(['nov. 9.', 'nov. 10.'])
    expect(format([START + 2 * HOUR], 30 * DAY)).toEqual(['2026. nov.'])
  })

  it('shows seconds when ticks are less than a minute apart', () => {
    const format = timeTickFormatter('hu', 'UTC')

    expect(format([START, START + 30], 30)).toEqual(['22:00:00\nnov. 8.', '22:00:30'])
  })

  it('falls back to UTC for a zone the runtime does not know', () => {
    const format = timeTickFormatter('hu', 'Mars/Olympus_Mons')

    expect(format([START], HOUR)).toEqual(['22:00\nnov. 8.'])
  })

  it('still formats when the locale tag is malformed', () => {
    const format = timeTickFormatter('not a locale!', 'Mars/Olympus_Mons')

    expect(format([START, START + HOUR], HOUR)).toHaveLength(2)
    expect(wholeNumberFormatter('not a locale!').format(1234.4)).toMatch(/^1.?234$/)
  })
})
