import type { UserSession } from '../../../api/types'
import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ChangePasswordCard from '../../../components/account/ChangePasswordCard.vue'
import SessionsCard from '../../../components/account/SessionsCard.vue'
import { useNotify } from '../../../composables/useNotify'
import { click, fill, mountComponent, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'

function input(name: string): HTMLInputElement {
  const el = document.querySelector<HTMLInputElement>(`input[name="${name}"]`)
  if (!el)
    throw new Error(`No input ${name}`)
  return el
}

function session(overrides: Partial<UserSession> = {}): UserSession {
  return {
    id: 'a1',
    ip_address: '10.0.0.7',
    user_agent: 'Mozilla/5.0',
    browser: 'Firefox',
    platform: 'macOS',
    last_active_at: new Date().toISOString(),
    current: true,
    ...overrides,
  }
}

async function fillPassword(current: string, next: string, confirmation: string): Promise<void> {
  fill('input[name="current_password"]', current)
  await flushPromises()
  fill('input[name="password"]', next)
  await flushPromises()
  fill('input[name="password_confirmation"]', confirmation)
  await flushPromises()
}

describe('account change password', () => {
  it('sends the change, clears the fields and notifies', async () => {
    const server = fakeApi({
      'PUT /me/password': json(null, 204),
    })
    mountComponent(ChangePasswordCard)

    await expect.poll(() => document.querySelector('input[name="current_password"]')).not.toBeNull()
    await fillPassword('old-password', 'a-new-long-password', 'a-new-long-password')
    await submit('input[name="current_password"]')

    await expect.poll(() => server.of('PUT /me/password')).toHaveLength(1)
    expect(server.of('PUT /me/password')[0]?.body).toEqual({
      current_password: 'old-password',
      password: 'a-new-long-password',
      password_confirmation: 'a-new-long-password',
    })
    await expect.poll(() => input('current_password').value).toBe('')
    expect(input('password').value).toBe('')
    expect(input('password_confirmation').value).toBe('')
    expect(useNotify().notices.value.map(n => n.text)).toContain('Your password has been changed.')
  })

  it('shows a wrong current password on its field', async () => {
    const server = fakeApi({
      'PUT /me/password': problem(422, {
        title: 'The given data was invalid.',
        errors: {
          current_password: ['The password is incorrect.'],
        },
      }),
    })
    mountComponent(ChangePasswordCard)

    await expect.poll(() => document.querySelector('input[name="current_password"]')).not.toBeNull()
    await fillPassword('wrong-password', 'a-new-long-password', 'a-new-long-password')
    await submit('input[name="current_password"]')

    await expect.poll(() => document.body.textContent).toContain('The password is incorrect.')
    expect(server.of('PUT /me/password')).toHaveLength(1)
    // The typed values stay so the user can correct them.
    expect(input('password').value).toBe('a-new-long-password')
  })

  it('does not send a confirmation that does not match', async () => {
    const server = fakeApi({})
    mountComponent(ChangePasswordCard)

    await expect.poll(() => document.querySelector('input[name="current_password"]')).not.toBeNull()
    await fillPassword('old-password', 'a-new-long-password', 'something-else-1')
    await submit('input[name="current_password"]')

    await expect.poll(() => document.body.textContent).toContain('The passwords do not match')
    expect(server.calls).toHaveLength(0)
  })
})

describe('account sessions', () => {
  it('lists sessions with this device marked', async () => {
    fakeApi({
      'GET /me/sessions': json({
        data: [
          session(),
          session({
            id: 'b2',
            current: false,
            browser: null,
            platform: null,
            user_agent: 'curl/8.7.1',
            ip_address: '192.0.2.4',
          }),
        ],
      }),
    })
    mountComponent(SessionsCard)

    await expect.poll(() => document.querySelector('[data-test="sessions"]')?.textContent ?? '').toContain('Firefox · macOS')
    const text = document.querySelector('[data-test="sessions"]')?.textContent ?? ''
    expect(text).toContain('This device')
    expect(text).toContain('curl/8.7.1')
    expect(text).toContain('192.0.2.4')
    expect(text).toContain('Last active')
    expect(document.body.textContent).not.toContain('You are not signed in on any other device.')
  })

  it('signs out all other devices behind a password prompt', async () => {
    const server = fakeApi({
      'GET /me/sessions': [
        json({
          data: [
            session(),
            session({ id: 'b2', current: false }),
          ],
        }),
        json({
          data: [
            session(),
          ],
        }),
      ],
      'POST /me/sessions/sign-out-others': [
        problem(422, {
          title: 'The given data was invalid.',
          errors: {
            password: ['The password is incorrect.'],
          },
        }),
        json(null, 204),
      ],
    })
    mountComponent(SessionsCard)

    await expect.poll(() => document.querySelectorAll('[data-test="sessions"] .v-list-item')).toHaveLength(2)
    await click('[data-test="sign-out-others"]')
    await expect.poll(() => document.querySelector('input[name="sign_out_password"]')).not.toBeNull()

    fill('input[name="sign_out_password"]', 'wrong-password')
    await submit('input[name="sign_out_password"]')
    await expect.poll(() => document.body.textContent).toContain('The password is incorrect.')

    await flushPromises()
    fill('input[name="sign_out_password"]', 'correct horse battery')
    await submit('input[name="sign_out_password"]')

    await expect.poll(() => server.of('POST /me/sessions/sign-out-others')).toHaveLength(2)
    expect(server.of('POST /me/sessions/sign-out-others')[1]?.body).toEqual({ password: 'correct horse battery' })
    await expect.poll(() => document.querySelectorAll('[data-test="sessions"] .v-list-item')).toHaveLength(1)
    expect(server.of('GET /me/sessions')).toHaveLength(2)
    expect(document.body.textContent).toContain('You are not signed in on any other device.')
    expect(useNotify().notices.value.map(n => n.text)).toContain('You have been signed out on all other devices.')
  })
})
