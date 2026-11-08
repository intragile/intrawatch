import type { ChannelTypeInfo } from '../../../api/types'
import { describe, expect, it } from 'vitest'
import { CHANNEL_FIELDS, CHANNEL_TYPES } from '../fields'
import fixture from './channel-types.fixture.json'

const catalogue = fixture as unknown as ChannelTypeInfo[]

function sorted(values: readonly string[]): string[] {
  return [...values].sort()
}

describe('channel field specs', () => {
  it('field specs cover every channel type\'s config and secret keys', () => {
    expect(sorted(CHANNEL_TYPES)).toEqual(sorted(catalogue.map(c => c.type)))
    for (const info of catalogue) {
      const specs = CHANNEL_FIELDS[info.type]
      const secrets = specs.filter(f => f.kind === 'secret').map(f => f.key)
      const config = specs.filter(f => f.kind !== 'secret').map(f => f.key)
      expect(sorted(config), `${info.type} config`).toEqual(sorted(info.config_keys))
      expect(sorted(secrets), `${info.type} secrets`).toEqual(sorted(info.secret_keys))
    }
  })

  it('every default of the catalogue belongs to a config field', () => {
    for (const info of catalogue) {
      const keys = new Set(CHANNEL_FIELDS[info.type].map(f => f.key))
      for (const key of Object.keys(info.defaults))
        expect(keys.has(key), `${info.type}.${key}`).toBe(true)
    }
  })

  it('every destination key of the catalogue points at a config field', () => {
    for (const info of catalogue) {
      const specs = CHANNEL_FIELDS[info.type]
      for (const path of info.destination_keys) {
        const [head = '', sub] = path.split('.')
        expect(info.config_keys, `${info.type}.${path}`).toContain(head)
        const spec = specs.find(f => f.key === head && f.kind !== 'secret')
        expect(spec, `${info.type}.${path}`).toBeDefined()
        if (sub !== undefined)
          expect(spec?.fields?.some(f => f.key === sub), `${info.type}.${path}`).toBe(true)
      }
    }
  })
})
