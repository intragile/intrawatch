import { describe, expect, it } from 'vitest'
import { meWith, workspace } from '../../test/fixtures'
import { click, mountApp } from '../../test/mount'
import { fakeApi, json, problem } from '../../test/server'

const token = 'a'.repeat(32) + 'B'.repeat(32)
const second = workspace({
  id: '01JBBBBBBBBBBBBBBBBBBBBBBB',
  slug: 'second',
  name: 'Second',
  role: 'editor',
})

describe('invitation acceptance', () => {
  it('accepts the invitation and opens the workspace', async () => {
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
    const { router } = await mountApp(`/invitations/${token}`)
    await expect.poll(() => document.querySelector('[data-test="accept-invitation"]')).not.toBeNull()

    await click('[data-test="accept-invitation"]')

    await expect.poll(() => router.currentRoute.value.params.workspace).toBe('second')
    expect(router.currentRoute.value.name).toBe('ws.monitors')
    expect(server.of('POST /invitations/accept')[0]?.body).toEqual({ token })
    expect(server.of('GET /me')).toHaveLength(2)
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
    const { router } = await mountApp(`/invitations/${token}`)
    await expect.poll(() => document.querySelector('[data-test="accept-invitation"]')).not.toBeNull()

    await click('[data-test="accept-invitation"]')

    await expect.poll(() => document.querySelector('[data-test="invitation-error"]')?.textContent)
      .toContain('This invitation is invalid, expired or meant for another e-mail address.')
    expect(router.currentRoute.value.name).toBe('invitation')
    expect(document.querySelector('[data-test="invitation-home"]')).not.toBeNull()
  })

  it('sends a signed-out visitor to the login and back', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': problem(401, { title: 'Unauthenticated.' }),
    })
    const { router } = await mountApp(`/invitations/${token}`)

    await expect.poll(() => router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe(`/invitations/${token}`)
  })
})
