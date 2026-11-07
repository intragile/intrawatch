import { describe, expect, it } from 'vitest'
import { ApiError, fieldErrors, formError } from '..'

function err(errors?: Record<string, string[]>, detail?: string) {
  const e = new ApiError(422, 'about:blank', 'Invalid', detail)
  e.errors = errors
  return e
}

describe('problem helpers', () => {
  it('fieldErrors maps dotted keys', () => {
    const e = err({
      'name': ['Too short', 'Other'],
      'config.url': ['Bad url'],
      'config.headers.0': ['Bad header'],
    })
    expect(fieldErrors(e)).toEqual({ 'name': 'Too short', 'config.url': 'Bad url', 'config.headers.0': 'Bad header' })
    expect(fieldErrors(e, 'config')).toEqual({ 'url': 'Bad url', 'headers.0': 'Bad header' })
    expect(fieldErrors(null)).toEqual({})
  })

  it('formError collects unknown keys', () => {
    const e = err({
      name: ['Too short'],
      interval: ['Too low'],
      other: ['Nope'],
    }, 'Invalid data')
    expect(formError(e, ['name'])).toBe('Invalid data\nToo low\nNope')
    expect(formError(err({
      name: ['x'],
    }), ['name'])).toBeNull()
    expect(formError(null, [])).toBeNull()
  })

  it('formError accepts known fields relative to a prefix', () => {
    const e = err({
      'config.url': ['Bad url'],
      'config.method': ['Bad method'],
      'name': ['Short'],
    })
    expect(formError(e, ['url'], 'config')).toBe('Bad method\nShort')
    expect(formError(e, ['config.url', 'config.method', 'name'])).toBeNull()
  })
})
