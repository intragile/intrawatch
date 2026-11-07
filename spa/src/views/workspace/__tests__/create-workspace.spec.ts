import { describe, expect, it } from 'vitest'
import { meWith } from '../../../test/fixtures'
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
})
