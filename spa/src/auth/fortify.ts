import { api, ApiError } from '../api'

// Fortify answers most actions with an empty JSON body; only the shapes read below matter.

interface Outcome<T> {
  data?: T
  error?: unknown
  response: Response
}

async function call<T>(request: Promise<Outcome<T>>): Promise<T | undefined> {
  const { data, error, response } = await request
  if (error !== undefined && error !== null) {
    throw error instanceof ApiError
      ? error
      : new ApiError(response.status, 'about:blank', response.statusText || `HTTP ${response.status}`)
  }
  return data
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export type TwoFactorAnswer = { code: string } | { recovery_code: string }

export interface PasswordReset {
  token: string
  email: string
  password: string
  password_confirmation: string
}

/** Starts a session; `two-factor` means the TOTP challenge must be answered next. */
export async function login(email: string, password: string, remember: boolean): Promise<'ok' | 'two-factor'> {
  const data = await call(api.POST('/auth/login', {
    body: {
      email,
      password,
      remember: remember ? '1' : '0',
    },
  }))
  return isRecord(data) && data.two_factor === true
    ? 'two-factor'
    : 'ok'
}

export async function answerTwoFactorChallenge(answer: TwoFactorAnswer): Promise<void> {
  await call(api.POST('/auth/two-factor-challenge', { body: answer }))
}

export async function logout(): Promise<void> {
  await call(api.POST('/auth/logout'))
}

export async function sendPasswordResetLink(email: string): Promise<void> {
  await call(api.POST('/auth/forgot-password', {
    body: { email },
  }))
}

export async function resetPassword(body: PasswordReset): Promise<void> {
  await call(api.POST('/auth/reset-password', { body }))
}

export async function confirmPassword(password: string): Promise<void> {
  await call(api.POST('/auth/user/confirm-password', {
    body: { password },
  }))
}

export async function enableTwoFactor(): Promise<void> {
  await call(api.POST('/auth/user/two-factor-authentication', { body: {} }))
}

export async function confirmTwoFactor(code: string): Promise<void> {
  await call(api.POST('/auth/user/confirmed-two-factor-authentication', {
    body: { code },
  }))
}

export async function disableTwoFactor(): Promise<void> {
  await call(api.DELETE('/auth/user/two-factor-authentication'))
}

export async function twoFactorQrCode(): Promise<string | null> {
  const data = await call(api.GET('/auth/user/two-factor-qr-code'))
  return isRecord(data) && typeof data.svg === 'string'
    ? data.svg
    : null
}

export async function twoFactorSecretKey(): Promise<string | null> {
  const data = await call(api.GET('/auth/user/two-factor-secret-key'))
  return isRecord(data) && typeof data.secretKey === 'string'
    ? data.secretKey
    : null
}

export async function recoveryCodes(): Promise<string[]> {
  const data: unknown = await call(api.GET('/auth/user/two-factor-recovery-codes'))
  return Array.isArray(data)
    ? data.filter((c): c is string => typeof c === 'string')
    : []
}

export async function regenerateRecoveryCodes(): Promise<void> {
  await call(api.POST('/auth/user/two-factor-recovery-codes'))
}
