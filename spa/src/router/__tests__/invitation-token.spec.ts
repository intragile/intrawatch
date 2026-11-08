import type { RouteLocationNormalized } from 'vue-router'
import { afterEach, describe, expect, it } from 'vitest'
import {
  captureInvitationToken,
  clearInvitationToken,
  INVITATION_TOKEN_KEY,
  peekInvitationToken,
} from '../invitationToken'

const token = 'a'.repeat(32) + 'B'.repeat(32)

function route(name: string, hash = '', params: Record<string, string> = {}): RouteLocationNormalized {
  return {
    name,
    hash,
    params,
    query: {},
    path: '/',
    fullPath: '/',
    matched: [],
    meta: {},
    redirectedFrom: undefined,
  } as RouteLocationNormalized
}

afterEach(() => {
  clearInvitationToken()
  sessionStorage.clear()
})

describe('captureInvitationToken', () => {
  it('stores a token from a #token= fragment and drops the hash', () => {
    expect(captureInvitationToken(route('invitation', `#token=${token}`)))
      .toEqual({ name: 'invitation', hash: '', replace: true })
    expect(sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBe(token)
    expect(peekInvitationToken()).toBe(token)
  })

  it('accepts a bare token fragment', () => {
    expect(captureInvitationToken(route('invitation', `#${token}`)))
      .toEqual({ name: 'invitation', hash: '', replace: true })
    expect(peekInvitationToken()).toBe(token)
  })

  it('takes the token of a legacy path link', () => {
    expect(captureInvitationToken(route('invitation-legacy', '', { token })))
      .toEqual({ name: 'invitation', hash: '', replace: true })
    expect(peekInvitationToken()).toBe(token)
  })

  it('drops an invalid token from the address bar without storing it', () => {
    expect(captureInvitationToken(route('invitation', '#token=short')))
      .toEqual({ name: 'invitation', hash: '', replace: true })
    expect(peekInvitationToken()).toBeNull()
  })

  it('leaves the plain invitation route and other routes alone', () => {
    expect(captureInvitationToken(route('invitation'))).toBeUndefined()
    expect(captureInvitationToken(route('ws.monitors', `#token=${token}`))).toBeUndefined()
    expect(peekInvitationToken()).toBeNull()
  })

  it('clears the stored token', () => {
    captureInvitationToken(route('invitation', `#token=${token}`))
    clearInvitationToken()
    expect(peekInvitationToken()).toBeNull()
    expect(sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBeNull()
  })

  it('keeps the token in memory when sessionStorage is blocked', () => {
    const original = Object.getOwnPropertyDescriptor(window, 'sessionStorage')
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      get() {
        throw new DOMException('blocked', 'SecurityError')
      },
    })
    try {
      captureInvitationToken(route('invitation', `#token=${token}`))
      expect(peekInvitationToken()).toBe(token)
      clearInvitationToken()
      expect(peekInvitationToken()).toBeNull()
    }
    finally {
      if (original)
        Object.defineProperty(window, 'sessionStorage', original)
    }
  })
})
