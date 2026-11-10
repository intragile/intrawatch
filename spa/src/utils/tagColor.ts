/** The color the API gives a tag created without one. */
export const DEFAULT_TAG_COLOR = '#6b7280'

/**
 * Preset tag colors: mid-tone fills that stand out on both the light and the dark surface.
 * The chip text is picked per color by `tagTextColor`, so every preset stays readable.
 */
export const TAG_PALETTE = [
  { key: 'gray', hex: DEFAULT_TAG_COLOR },
  { key: 'red', hex: '#dc2626' },
  { key: 'orange', hex: '#ea580c' },
  { key: 'amber', hex: '#ca8a04' },
  { key: 'green', hex: '#16a34a' },
  { key: 'teal', hex: '#0d9488' },
  { key: 'sky', hex: '#0284c7' },
  { key: 'blue', hex: '#2563eb' },
  { key: 'violet', hex: '#7c3aed' },
  { key: 'pink', hex: '#db2777' },
] as const

const HEX = /^#[0-9a-f]{6}$/i

/** Whether the value is a `#rrggbb` color, the only form the API accepts. */
export function isHexColor(value: string): boolean {
  return HEX.test(value)
}

/** WCAG 2 relative luminance of a `#rrggbb` color. */
export function relativeLuminance(hex: string): number {
  const channel = (offset: number): number => {
    const c = Number.parseInt(hex.slice(offset, offset + 2), 16) / 255
    return c <= 0.04045
      ? c / 12.92
      : ((c + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)
}

/** WCAG 2 contrast ratio of two relative luminances. */
export function contrastRatio(a: number, b: number): number {
  const [light, dark] = a > b
    ? [a, b]
    : [b, a]
  return (light + 0.05) / (dark + 0.05)
}

/**
 * Black or white, whichever contrasts more with the fill. The better of the two always
 * reaches at least 4.58:1, so tag text meets WCAG AA (4.5:1) on any color.
 * An invalid color falls back to the default tag color.
 */
export function tagTextColor(fill: string): '#000000' | '#ffffff' {
  const l = relativeLuminance(isHexColor(fill)
    ? fill
    : DEFAULT_TAG_COLOR)
  return contrastRatio(l, 0) >= contrastRatio(l, 1)
    ? '#000000'
    : '#ffffff'
}
