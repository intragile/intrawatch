import { describe, expect, it } from 'vitest'
import { me, meWith } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'

describe('create workspace', () => {
  it('creates a workspace from the switcher and opens it', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        json({
          data: meWith({}),
        }),
        json({
          data: meWith({}, { id: '01JBBBBBBBBBBBBBBBBBBBBBBB', slug: 'second', name: 'Second' }),
        }),
      ],
      'POST /workspaces': json({
        data: { slug: 'second', name: 'Second' },
      }, 201),
    })
    const { router } = await mountApp('/w/acme/settings')
    await expect.poll(() => document.querySelector('[data-test="create-workspace"]')).not.toBeNull()

    await click('[data-test="create-workspace"]')
    await expect.poll(() => document.querySelector('input[name="workspace_name"]')).not.toBeNull()
    fill('input[name="workspace_name"]', 'Second')
    await submit('input[name="workspace_name"]')

    await expect.poll(() => router.currentRoute.value.params.workspace).toBe('second')
    expect(server.of('POST /workspaces')[0]?.body).toEqual({ name: 'Second' })
  })

  it('hides the create button when the user may not create workspaces', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: {
          ...meWith({}),
          can_create_workspace: false,
        },
      }),
    })
    await mountApp('/w/acme/settings')
    await expect.poll(() => document.querySelector('.workspace-switcher')).not.toBeNull()

    expect(document.querySelector('[data-test="create-workspace"]')).toBeNull()
  })

  it('without a workspace, asks for an invitation instead of offering to create one', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me({ workspaces: [], can_create_workspace: false }),
      }),
    })
    await mountApp('/')
    await expect.poll(() => document.body.textContent).toContain('ask a workspace owner or admin to invite you')

    expect(document.body.textContent).not.toContain('Create workspace')
  })

  it('without a workspace, offers to create one when allowed', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me({ workspaces: [] }),
      }),
    })
    await mountApp('/')
    await expect.poll(() => document.body.textContent).toContain('Create workspace')
  })
})
