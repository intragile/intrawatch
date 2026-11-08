import { describe, expect, it } from 'vitest'
import { comparable, destinationChanged, requiredSecrets, withReenteredSecrets } from '../secrets'

describe('comparable', () => {
  it('treats null and absent as the same value', () => {
    expect(comparable(null)).toBeNull()
    expect(comparable(undefined)).toBeNull()
  })

  it('compares scalars as text and objects as JSON', () => {
    expect(comparable(587)).toBe(comparable('587'))
    expect(comparable(true)).toBe('true')
    expect(comparable({ a: 1 })).toBe('{"a":1}')
  })
})

describe('destinationChanged', () => {
  const http = ['url', 'proxy.url']

  it('mirrors the server for a monitor', () => {
    const base = { url: 'https://a.test', method: 'GET', proxy: null }
    expect(destinationChanged(http, base, { ...base, method: 'HEAD' })).toBe(false)
    expect(destinationChanged(http, base, { ...base, url: 'https://b.test' })).toBe(true)
    expect(destinationChanged(http, base, {
      ...base,
      proxy: { url: 'http://p.test' },
    })).toBe(true)
    expect(destinationChanged(['host', 'port'], { host: 'a', port: 1 }, { host: 'a', port: 2 })).toBe(true)
    expect(destinationChanged([], { grace_seconds: 1 }, { grace_seconds: 2 })).toBe(false)
  })

  it('mirrors the server for a channel', () => {
    const email = ['smtp.host', 'smtp.port', 'smtp.encryption']
    const smtp = {
      host: 'mail.test',
      port: 587,
      encryption: 'starttls',
      username: 'a',
    }
    expect(destinationChanged(email, { smtp }, {
      smtp: { ...smtp, username: 'b' },
    })).toBe(false)
    expect(destinationChanged(email, { smtp }, {
      smtp: { ...smtp, port: '587' },
    })).toBe(false)
    expect(destinationChanged(email, { smtp }, {
      smtp: { ...smtp, host: 'other.test' },
    })).toBe(true)
    expect(destinationChanged(email, { smtp }, { smtp: null })).toBe(true)
  })

  it('follows paths of any depth', () => {
    expect(destinationChanged(['a.b.c'], {
      a: {
        b: { c: 1 },
      },
    }, {
      a: {
        b: { c: 2 },
      },
    })).toBe(true)
    expect(destinationChanged(['a.b.c'], {
      a: {
        b: { c: 1, d: 1 },
      },
    }, {
      a: {
        b: { c: 1, d: 2 },
      },
    })).toBe(false)
    expect(destinationChanged(['a.b.c'], { a: null }, {})).toBe(false)
  })
})

describe('requiredSecrets', () => {
  const keys = ['url']
  const stored = ['bearer_token', 'basic_password']

  it('asks for nothing while creating', () => {
    expect(requiredSecrets(keys, null, { url: 'https://b.test' }, stored)).toEqual([])
  })

  it('asks for nothing while the destination stays', () => {
    expect(requiredSecrets(keys, { url: 'https://a.test' }, { url: 'https://a.test', method: 'HEAD' }, stored)).toEqual([])
  })

  it('asks for every stored secret once the destination changes', () => {
    expect(requiredSecrets(keys, { url: 'https://a.test' }, { url: 'https://b.test' }, stored)).toEqual(stored)
  })

  it('asks for nothing without a current config', () => {
    expect(requiredSecrets(keys, { url: 'https://a.test' }, null, stored)).toEqual([])
  })
})

describe('withReenteredSecrets', () => {
  it('returns the draft unchanged when nothing must be entered again', () => {
    const draft = { a: 'x' }
    const result = withReenteredSecrets(draft, [], ['a'])
    expect(result.draft).toBe(draft)
    expect(result.missing).toEqual([])
  })

  it('keeps typed values, reports empty ones on screen and clears the others', () => {
    const draft = { typed: 'new', empty: '', cleared: null }
    const result = withReenteredSecrets(draft, [
      'typed',
      'empty',
      'absent',
      'cleared',
      'hidden',
    ], ['typed', 'empty', 'absent', 'cleared'])
    expect(result.missing).toEqual(['empty', 'absent'])
    expect(result.draft.typed).toBe('new')
    expect(result.draft.cleared).toBeNull()
    expect(result.draft.hidden).toBeNull()
    expect(draft).toEqual({ typed: 'new', empty: '', cleared: null })
  })

  it('does not mutate the draft it was given', () => {
    const draft = {}
    const result = withReenteredSecrets(draft, ['hidden'], [])
    expect(result.draft).toEqual({ hidden: null })
    expect(result.missing).toEqual([])
    expect(draft).toEqual({})
  })
})
