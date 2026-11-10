import { describe, expect, it } from 'vitest'
import { contrastRatio, isHexColor, relativeLuminance, TAG_PALETTE, tagTextColor } from '../tagColor'

function textContrast(fill: string): number {
  const text = tagTextColor(fill) === '#000000'
    ? 0
    : 1
  return contrastRatio(relativeLuminance(fill), text)
}

describe('tag colors', () => {
  it('accepts only #rrggbb', () => {
    expect(isHexColor('#2563eb')).toBe(true)
    expect(isHexColor('#2563EB')).toBe(true)
    expect(isHexColor('2563eb')).toBe(false)
    expect(isHexColor('#256')).toBe(false)
    expect(isHexColor('#2563ebff')).toBe(false)
  })

  it('picks black or white by luminance', () => {
    expect(tagTextColor('#ffffff')).toBe('#000000')
    expect(tagTextColor('#facc15')).toBe('#000000')
    expect(tagTextColor('#000000')).toBe('#ffffff')
    expect(tagTextColor('#7c3aed')).toBe('#ffffff')
  })

  it('keeps tag text at WCAG AA on every preset and on a sweep of colors', () => {
    for (const preset of TAG_PALETTE)
      expect(textContrast(preset.hex)).toBeGreaterThanOrEqual(4.5)
    for (let r = 0; r < 256; r += 17) {
      for (let g = 0; g < 256; g += 17) {
        for (let b = 0; b < 256; b += 17) {
          const hex = `#${[r, g, b].map(c => c.toString(16).padStart(2, '0')).join('')}`
          expect(textContrast(hex)).toBeGreaterThanOrEqual(4.5)
        }
      }
    }
  })

  it('falls back to the default color for an invalid fill', () => {
    expect(tagTextColor('nonsense')).toBe(tagTextColor('#6b7280'))
  })
})
