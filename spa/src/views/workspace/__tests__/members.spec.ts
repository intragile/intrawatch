import { describe, expect, it } from 'vitest'
import { meWith, viewerCan, workspace } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import { choose, page } from './helpers'

const members = [
  {
    id: 1,
    name: 'Ada Lovelace',
    email: 'ada@example.com',
    role: 'owner',
  },
  {
    id: 2,
    name: 'Bob Builder',
    email: 'bob@example.com',
    role: 'editor',
  },
]

const invitation = {
  id: 7,
  email: 'cy@example.com',
  role: 'viewer',
  invited_by: { id: 1, name: 'Ada Lovelace' },
  expires_at: '2026-11-15T12:00:00+00:00',
  expired: false,
  created_at: '2026-11-08T12:00:00+00:00',
}

describe('members', () => {
  it('403 on member change shows problem and refreshes me', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        json({
          data: meWith({}),
        }),
        json({
          data: meWith({ role: 'viewer', can: viewerCan }),
        }),
      ],
      'GET /workspaces/acme/members': json(page(members)),
      'GET /workspaces/acme/invitations': json(page([])),
      'PATCH /workspaces/acme/members/2': problem(403, { title: 'This action is unauthorized.' }),
    })
    const { wrapper } = await mountApp('/w/acme/members')
    await expect.poll(() => document.body.textContent).toContain('Bob Builder')

    choose(wrapper, 'role-2', 'viewer')

    await expect.poll(() => document.querySelector('[data-test="row-error-2"]')?.textContent).toContain('This action is unauthorized.')
    expect(server.of('PATCH /workspaces/acme/members/2')[0]?.body).toEqual({ role: 'viewer' })
    await expect.poll(() => server.of('GET /me')).toHaveLength(2)
    // The refreshed permissions remove the write controls.
    await expect.poll(() => document.querySelector('[data-test="role-2"]')).toBeNull()
    expect(document.querySelector('[data-test="remove-2"]')).toBeNull()
  })

  it('owner cannot be demoted below last owner: api 422 shown on the row', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/members': json(page(members)),
      'GET /workspaces/acme/invitations': json(page([])),
      'PATCH /workspaces/acme/members/1': problem(422, {
        title: 'The given data was invalid.',
        errors: {
          role: ['A workspace must keep at least one owner.'],
        },
      }),
    })
    const { wrapper } = await mountApp('/w/acme/members')
    await expect.poll(() => document.body.textContent).toContain('Ada Lovelace')

    choose(wrapper, 'role-1', 'admin')

    await expect.poll(() => document.querySelector('[data-test="row-error-1"]')?.textContent).toContain('A workspace must keep at least one owner.')
    expect(document.querySelector('[data-test="row-error-2"]')).toBeNull()
  })

  it('invites by e-mail, shows field errors and refreshes the pending list', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/members': json(page(members)),
      'GET /workspaces/acme/invitations': [
        json(page([])),
        json(page([invitation])),
      ],
      'POST /workspaces/acme/members': [
        problem(422, {
          errors: {
            email: ['The user is already a member of this workspace.'],
          },
        }),
        json({
          data: {
            email: 'cy@example.com',
            role: 'viewer',
            expires_at: '2026-11-15T12:00:00+00:00',
          },
        }, 202),
      ],
    })
    await mountApp('/w/acme/members')
    await expect.poll(() => document.querySelector('input[name="member_email"]')).not.toBeNull()

    fill('input[name="member_email"]', 'bob@example.com')
    await submit('input[name="member_email"]')
    await expect.poll(() => document.body.textContent).toContain('The user is already a member of this workspace.')

    fill('input[name="member_email"]', 'cy@example.com')
    await submit('input[name="member_email"]')
    await expect.poll(() => server.of('POST /workspaces/acme/members')).toHaveLength(2)
    expect(server.of('POST /workspaces/acme/members')[1]?.body).toEqual({ email: 'cy@example.com', role: 'viewer', locale: 'en' })
    await expect.poll(() => document.body.textContent).toContain('Invitation sent to cy@example.com.')
    await expect.poll(() => document.querySelector('[data-test="invitation-7"]')?.textContent).toContain('cy@example.com')
    expect(server.of('GET /workspaces/acme/invitations')).toHaveLength(2)
    // The member list is untouched: nobody joins before accepting.
    expect(server.of('GET /workspaces/acme/members')).toHaveLength(1)
  })

  it('lists pending invitations and revokes one after confirmation', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/members': json(page(members)),
      'GET /workspaces/acme/invitations': json(page([
        invitation,
        {
          ...invitation,
          id: 8,
          email: 'old@example.com',
          expired: true,
        },
      ])),
      'DELETE /workspaces/acme/invitations/7': json(null, 204),
    })
    await mountApp('/w/acme/members')
    await expect.poll(() => document.querySelector('[data-test="invitation-7"]')?.textContent).toContain('cy@example.com')
    expect(document.querySelector('[data-test="invitation-8"]')?.textContent).toContain('Expired')
    expect(document.querySelector('[data-test="invitation-7"]')?.textContent).not.toContain('Expired')

    await click('[data-test="revoke-7"]')
    await click('[data-test="confirm"]')

    await expect.poll(() => server.of('DELETE /workspaces/acme/invitations/7')).toHaveLength(1)
    await expect.poll(() => document.querySelector('[data-test="invitation-7"]')).toBeNull()
    expect(document.querySelector('[data-test="invitation-8"]')).not.toBeNull()
  })

  it('does not load invitations without the manage ability', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ role: 'viewer', can: viewerCan }),
      }),
      'GET /workspaces/acme/members': json(page(members)),
    })
    await mountApp('/w/acme/members')
    await expect.poll(() => document.body.textContent).toContain('Bob Builder')

    expect(server.of('GET /workspaces/acme/invitations')).toHaveLength(0)
    expect(document.querySelector('input[name="member_email"]')).toBeNull()
    expect(server.unexpected).toEqual([])
  })

  it('removes a member after confirmation', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith(workspace()),
      }),
      'GET /workspaces/acme/members': json(page(members)),
      'GET /workspaces/acme/invitations': json(page([])),
      'DELETE /workspaces/acme/members/2': json(null, 204),
    })
    await mountApp('/w/acme/members')
    await expect.poll(() => document.querySelector('[data-test="remove-2"]')).not.toBeNull()

    await click('[data-test="remove-2"]')
    await click('[data-test="confirm"]')

    await expect.poll(() => server.of('DELETE /workspaces/acme/members/2')).toHaveLength(1)
  })
})
