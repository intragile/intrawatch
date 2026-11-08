import { afterEach, describe, expect, it } from 'vitest'
import { clearInvitationToken, INVITATION_TOKEN_KEY } from '../../router/invitationToken'
import { me, meWith, workspace } from '../../test/fixtures'
import { click, fill, mountApp, submit } from '../../test/mount'
import { fakeApi, json, problem } from '../../test/server'

const token = 'a'.repeat(32) + 'B'.repeat(32)
const second = workspace({
  id: '01JBBBBBBBBBBBBBBBBBBBBBBB',
  slug: 'second',
  name: 'Second',
  role: 'editor',
})

function acceptButton(): HTMLButtonElement | null {
  return document.querySelector<HTMLButtonElement>('[data-test="accept-invitation"]')
}

// src/test/setup.ts only clears localStorage.
afterEach(() => {
  clearInvitationToken()
  sessionStorage.clear()
})

describe('invitation acceptance', () => {
  it('reads the token from the fragment, clears the address bar and accepts', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        json({
          data: meWith({}),
        }),
        json({
          data: meWith({}, second),
        }),
      ],
      'POST /invitations/accept': json({ data: second }),
      'GET /workspaces/second/monitors': json({
        data: [],
        meta: { next_cursor: null, limit: 50 },
      }),
    })
    const { router } = await mountApp(`/invitations#token=${token}`)
    await expect.poll(() => acceptButton()).not.toBeNull()

    expect(router.currentRoute.value.name).toBe('invitation')
    expect(router.currentRoute.value.hash).toBe('')
    expect(router.currentRoute.value.fullPath).toBe('/invitations')

    await click('[data-test="accept-invitation"]')

    await expect.poll(() => router.currentRoute.value.params.workspace).toBe('second')
    expect(router.currentRoute.value.name).toBe('ws.monitors')
    expect(server.of('POST /invitations/accept')[0]?.body).toEqual({ token })
    expect(server.of('GET /me')).toHaveLength(2)
    expect(sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBeNull()
  })

  it('shows one error for any unusable invitation', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'POST /invitations/accept': problem(422, {
        title: 'The given data was invalid.',
        errors: {
          token: ['The invitation is invalid or has expired.'],
        },
      }),
    })
    const { router } = await mountApp(`/invitations#token=${token}`)
    await expect.poll(() => acceptButton()).not.toBeNull()

    await click('[data-test="accept-invitation"]')

    await expect.poll(() => document.querySelector('[data-test="invitation-error"]')?.textContent)
      .toContain('This invitation is invalid, expired or meant for another e-mail address.')
    expect(router.currentRoute.value.name).toBe('invitation')
    expect(document.querySelector('[data-test="invitation-home"]')).not.toBeNull()
    expect(sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBeNull()
  })

  it('sends a signed-out visitor to the login without the token and accepts after login', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        problem(401, { title: 'Unauthenticated.' }),
        json({
          data: me(),
        }),
        json({
          data: meWith({}, second),
        }),
      ],
      'POST /auth/login': json({ two_factor: false }),
      'POST /invitations/accept': json({ data: second }),
      'GET /workspaces/second/monitors': json({
        data: [],
        meta: { next_cursor: null, limit: 50 },
      }),
    })
    const { router } = await mountApp(`/invitations#token=${token}`)

    await expect.poll(() => router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/invitations')
    expect(router.currentRoute.value.fullPath).not.toContain(token)
    expect(JSON.stringify(router.currentRoute.value.query)).not.toContain(token)
    expect(sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBe(token)

    fill('input[name="email"]', 'ada@example.com')
    fill('input[name="password"]', 'correct horse battery')
    await submit('input[name="email"]')

    await expect.poll(() => router.currentRoute.value.fullPath).toBe('/invitations')
    await expect.poll(() => acceptButton()).not.toBeNull()
    await click('[data-test="accept-invitation"]')

    await expect.poll(() => router.currentRoute.value.name).toBe('ws.monitors')
    expect(server.of('POST /invitations/accept')[0]?.body).toEqual({ token })
    expect(sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBeNull()
  })

  it('takes the token out of a legacy path link at once', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'POST /invitations/accept': problem(422, { title: 'The given data was invalid.' }),
    })
    const { router } = await mountApp(`/invitations/${token}`)
    await expect.poll(() => acceptButton()).not.toBeNull()

    expect(router.currentRoute.value.name).toBe('invitation')
    expect(router.currentRoute.value.fullPath).toBe('/invitations')
    expect(sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBe(token)

    await click('[data-test="accept-invitation"]')

    await expect.poll(() => server.of('POST /invitations/accept')).toHaveLength(1)
    expect(server.of('POST /invitations/accept')[0]?.body).toEqual({ token })
  })

  it('follows a second invitation link opened in the same tab', async () => {
    const other = 'c'.repeat(32) + 'D'.repeat(32)
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'POST /invitations/accept': problem(422, { title: 'The given data was invalid.' }),
    })
    const { router } = await mountApp(`/invitations#token=${token}`)
    await expect.poll(() => acceptButton()).not.toBeNull()
    await click('[data-test="accept-invitation"]')
    await expect.poll(() => document.querySelector('[data-test="invitation-error"]')).not.toBeNull()
    expect(acceptButton()?.disabled).toBe(true)

    await router.push(`/invitations#token=${other}`)

    await expect.poll(() => acceptButton()?.disabled).toBe(false)
    expect(document.querySelector('[data-test="invitation-error"]')).toBeNull()
    expect(router.currentRoute.value.fullPath).toBe('/invitations')
    await click('[data-test="accept-invitation"]')
    await expect.poll(() => server.of('POST /invitations/accept')).toHaveLength(2)
    expect(server.of('POST /invitations/accept')[1]?.body).toEqual({ token: other })
  })

  it.each([
    ['/invitations'],
    ['/invitations#token=rovid'],
  ])('disables acceptance when %s carries no usable token', async (path) => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
    })
    await mountApp(path)
    await expect.poll(() => acceptButton()).not.toBeNull()

    expect(document.querySelector('[data-test="invitation-missing"]')?.textContent)
      .toContain('This link does not contain an invitation.')
    expect(acceptButton()?.disabled).toBe(true)
    expect(server.of('POST /invitations/accept')).toHaveLength(0)
  })
})
