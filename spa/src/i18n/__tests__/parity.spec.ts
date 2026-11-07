import { describe, expect, it } from 'vitest'
import en from '../en.json'
import hu from '../hu.json'

function keys(value: unknown, prefix = ''): string[] {
  if (typeof value !== 'object' || value === null)
    return [prefix]
  return Object.entries(value).flatMap(([k, v]) => keys(v, prefix
    ? `${prefix}.${k}`
    : k))
}

function lookup(messages: unknown, key: string): unknown {
  return key.split('.').reduce<unknown>((node, part) =>
    typeof node === 'object' && node !== null
      ? (node as Record<string, unknown>)[part]
      : undefined, messages)
}

describe('i18n', () => {
  it('i18n en and hu have identical key sets', () => {
    expect(keys(hu).sort()).toEqual(keys(en).sort())
  })

  it('has no empty messages', () => {
    for (const messages of [en, hu]) {
      for (const key of keys(messages))
        expect(String(lookup(messages, key)).trim(), key).not.toBe('')
    }
  })

  it('every literal key used in the source exists', () => {
    const sources = import.meta.glob<string>([
      '../../**/*.vue',
      '../../**/*.ts',
      '!../../**/__tests__/**',
      '!../../api/**',
    ], {
      query: '?raw',
      import: 'default',
      eager: true,
    })
    const used = new Set<string>()
    for (const source of Object.values(sources)) {
      for (const match of source.matchAll(/\bt\(\s*'([\w.-]+)'/g))
        used.add(match[1] as string)
    }
    expect(used.size).toBeGreaterThan(0)
    const missing = [...used].filter(key => typeof lookup(en, key) !== 'string')
    expect(missing).toEqual([])
  })
})
