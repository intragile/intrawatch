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

  it('adds a member by e-mail and shows field errors', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/members': json(page(members)),
      'POST /workspaces/acme/members': [
        problem(422, {
          errors: {
            email: ['The selected email is invalid.'],
          },
        }),
        json({
          data: {
            id: 3,
            name: 'Cy',
            email: 'cy@example.com',
            role: 'viewer',
          },
        }, 201),
      ],
    })
    await mountApp('/w/acme/members')
    await expect.poll(() => document.querySelector('input[name="member_email"]')).not.toBeNull()

    fill('input[name="member_email"]', 'nobody@example.com')
    await submit('input[name="member_email"]')
    await expect.poll(() => document.body.textContent).toContain('The selected email is invalid.')

    fill('input[name="member_email"]', 'cy@example.com')
    await submit('input[name="member_email"]')
    await expect.poll(() => server.of('POST /workspaces/acme/members')).toHaveLength(2)
    expect(server.of('POST /workspaces/acme/members')[1]?.body).toEqual({ email: 'cy@example.com', role: 'viewer' })
  })

  it('removes a member after confirmation', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith(workspace()),
      }),
      'GET /workspaces/acme/members': json(page(members)),
      'DELETE /workspaces/acme/members/2': json(null, 204),
    })
    await mountApp('/w/acme/members')
    await expect.poll(() => document.querySelector('[data-test="remove-2"]')).not.toBeNull()

    await click('[data-test="remove-2"]')
    await click('[data-test="confirm"]')

    await expect.poll(() => server.of('DELETE /workspaces/acme/members/2')).toHaveLength(1)
  })
})
