import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { shallowRef } from 'vue'
import { readSession, removeSession, writeSession } from '../utils/storage'

// The invitation e-mail carries the token in the URL fragment (`/admin/invitations#token=…`), which
// browsers never send, so it stays out of access logs. The router takes it out of the address bar
// at once and keeps it per tab until the invitation is accepted or rejected, so the login redirect
// never carries it in a query string.

export const INVITATION_TOKEN_KEY = 'intrawatch.invitation-token'

const TOKEN_RE = /^[A-Z0-9]{64}$/i

/** Used only when sessionStorage is blocked; survives in-app navigation, not a reload. */
let fallback: string | null = null

/**
 * Moves on every stash and clear, so a view already showing `/invitations` notices a second link
 * opened in the same tab (the router lands on the same address and reuses the component).
 */
const revision = shallowRef(0)

/** The token of a `#token=<t>` or bare `#<t>` fragment, or of a legacy `/invitations/<t>` path. */
export function extractToken(to: RouteLocationNormalized): string | null {
  const hash = to.hash.replace(/^#/, '')
  const candidate = hash !== ''
    ? (hash.startsWith('token=')
        ? hash.slice('token='.length)
        : hash)
    : to.params.token
  return typeof candidate === 'string' && TOKEN_RE.test(candidate)
    ? candidate
    : null
}

export function stashInvitationToken(token: string): void {
  fallback = writeSession(INVITATION_TOKEN_KEY, token)
    ? null
    : token
  revision.value++
}

export function peekInvitationToken(): string | null {
  void revision.value
  const stored = readSession(INVITATION_TOKEN_KEY) ?? fallback
  return stored !== null && TOKEN_RE.test(stored)
    ? stored
    : null
}

export function clearInvitationToken(): void {
  fallback = null
  removeSession(INVITATION_TOKEN_KEY)
  revision.value++
}

/**
 * Stores the token of an invitation link and returns a replacing redirect to the token-free
 * `/invitations` address; `undefined` for every other location.
 */
export function captureInvitationToken(to: RouteLocationNormalized): RouteLocationRaw | undefined {
  const fromHash = to.name === 'invitation' && to.hash !== ''
  if (!fromHash && to.name !== 'invitation-legacy')
    return undefined
  const token = extractToken(to)
  if (token !== null)
    stashInvitationToken(token)
  return { name: 'invitation', hash: '', replace: true }
}
