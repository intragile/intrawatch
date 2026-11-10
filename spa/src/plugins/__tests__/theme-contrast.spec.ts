import { describe, expect, it } from 'vitest'
import { dark, light } from '../vuetify'

/** WCAG relative luminance of a #rrggbb colour. */
function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = Number.parseInt(hex.slice(i, i + 2), 16) / 255
    return c <= 0.03928
      ? c / 12.92
      : ((c + 0.055) / 1.055) ** 2.4
  }) as [number, number, number]
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [
    luminance(a),
    luminance(b),
  ].sort((x, y) => y - x) as [number, number]
  return (hi + 0.05) / (lo + 0.05)
}

const FILLS = [
  'primary',
  'secondary',
  'success',
  'error',
  'warning',
  'info',
  'maintenance',
  'idle',
]

describe('theme contrast', () => {
  // Buttons and chips with a colour fill put the matching on-colour (white unless set) on top of it.
  it.each([
    ['light', light],
    ['dark', dark],
  ] as const)('%s fills keep their text readable', (_, colors) => {
    for (const name of FILLS) {
      const fill = colors[name]!
      const text = colors[`on-${name}`] ?? '#FFFFFF'
      expect(contrast(fill, text), `${name} ${fill} with ${text}`).toBeGreaterThanOrEqual(4.5)
    }
  })
})
