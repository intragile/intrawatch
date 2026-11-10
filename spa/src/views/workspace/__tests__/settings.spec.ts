import { describe, expect, it } from 'vitest'
import { useSession } from '../../../stores/session'
import { meWith, viewerCan } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'

describe('workspace settings', () => {
  it('saves the name and timezone and refreshes me', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        json({
          data: meWith({}),
        }),
        json({
          data: meWith({ name: 'Acme Corp' }),
        }),
      ],
      'PATCH /workspaces/acme': json({
        data: { slug: 'acme' },
      }),
    })
    const { wrapper } = await mountApp('/w/acme/settings')
    await expect.poll(() => document.querySelector('input[name="settings_name"]')).not.toBeNull()
    expect(document.querySelector<HTMLInputElement>('input[name="settings_slug"]')?.readOnly).toBe(true)

    fill('input[name="settings_name"]', 'Acme Corp')
    await submit('input[name="settings_name"]')

    await expect.poll(() => server.of('PATCH /workspaces/acme')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme')[0]?.body).toEqual({ name: 'Acme Corp', timezone: 'Europe/Budapest' })
    await expect.poll(() => useSession().current?.name).toBe('Acme Corp')
    expect(wrapper.exists()).toBe(true)
  })

  it('links the header to the settings section of the workspace chapter', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
    })
    await mountApp('/w/acme/settings')
    await expect.poll(() => document.querySelector('input[name="settings_name"]')).not.toBeNull()

    // The link sits next to this screen's own title, not next to whichever h1 happens to come first.
    const title = [
      ...document.querySelectorAll('h1'),
    ].find(h => h.textContent?.trim() === 'Workspace settings')
    expect(title).toBeDefined()
    const link = title?.parentElement?.querySelector('[data-test="help-link"]')
    expect(link?.getAttribute('href')).toBe('/help/workspace/workspace#workspace-settings')
    expect(link?.getAttribute('aria-label')).toBe('Help: Workspace and members')
  })

  it('a viewer sees read-only settings and no delete', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ role: 'viewer', can: viewerCan }),
      }),
    })
    await mountApp('/w/acme/settings')
    await expect.poll(() => document.querySelector('input[name="settings_name"]')).not.toBeNull()

    expect(document.querySelector<HTMLInputElement>('input[name="settings_name"]')?.readOnly).toBe(true)
    expect(document.querySelector('[data-test="delete-workspace"]')).toBeNull()
    expect(document.querySelector('button[type="submit"]')).toBeNull()
  })

  it('deleting a workspace requires typing its name and switches workspace', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        json({
          data: meWith({}, { id: '01JBBBBBBBBBBBBBBBBBBBBBBB', slug: 'beta', name: 'Beta' }),
        }),
        json({
          data: meWith({ id: '01JBBBBBBBBBBBBBBBBBBBBBBB', slug: 'beta', name: 'Beta' }),
        }),
      ],
      'DELETE /workspaces/acme': json(null, 204),
    })
    const { router } = await mountApp('/w/acme/settings')
    await expect.poll(() => document.querySelector('[data-test="delete-workspace"]')).not.toBeNull()

    await click('[data-test="delete-workspace"]')
    await expect.poll(() => document.querySelector('input[name="confirm_name"]')).not.toBeNull()
    const confirm = () => document.querySelector<HTMLButtonElement>('[data-test="confirm"]')
    expect(confirm()?.disabled).toBe(true)

    fill('input[name="confirm_name"]', 'acme')
    await click('[data-test="confirm"]')
    expect(server.of('DELETE /workspaces/acme')).toHaveLength(0)

    fill('input[name="confirm_name"]', 'Acme')
    await expect.poll(() => confirm()?.disabled).toBe(false)
    await click('[data-test="confirm"]')

    await expect.poll(() => server.of('DELETE /workspaces/acme')).toHaveLength(1)
    await expect.poll(() => router.currentRoute.value.params.workspace).toBe('beta')
    expect(router.currentRoute.value.name).toBe('ws.monitors')
  })

  it('shows a 403 on delete and refreshes me', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'DELETE /workspaces/acme': problem(403, { title: 'This action is unauthorized.' }),
    })
    await mountApp('/w/acme/settings')
    await click('[data-test="delete-workspace"]')
    await expect.poll(() => document.querySelector('input[name="confirm_name"]')).not.toBeNull()
    fill('input[name="confirm_name"]', 'Acme')
    await expect.poll(() => document.querySelector<HTMLButtonElement>('[data-test="confirm"]')?.disabled).toBe(false)
    await click('[data-test="confirm"]')

    await expect.poll(() => document.body.textContent).toContain('This action is unauthorized.')
    await expect.poll(() => server.of('GET /me').length).toBe(2)
  })
})
