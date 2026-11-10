import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { me, viewerCan, workspace } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import { page } from './helpers'

const prod = { id: 3, name: 'prod', color: '#dc2626' }
const eu = { id: 4, name: 'eu-west', color: '#facc15' }

function base(extra: Parameters<typeof fakeApi>[0] = {}) {
  return fakeApi({
    'GET /setup': json({ required: false }),
    'GET /me': json({
      data: me(),
    }),
    ...extra,
  })
}

function row(id: number): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-test="tag-row-${id}"]`)
}

describe('tags', () => {
  it('lists every page of tags sorted by name, with readable chips', async () => {
    const server = base({
      'GET /workspaces/acme/tags': [
        json(page([prod], 'next')),
        json(page([eu])),
      ],
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => row(4)).not.toBeNull()

    expect(server.of('GET /workspaces/acme/tags')).toHaveLength(2)
    const names = [
      ...document.querySelectorAll('[data-test^="tag-row-"]'),
    ].map(r => r.querySelector('.v-chip')?.textContent?.trim())
    expect(names).toEqual(['eu-west', 'prod'])
    // Dark text on the light yellow, white text on the red.
    expect(row(4)?.querySelector<HTMLElement>('.v-chip')?.style.color).toBe('rgb(0, 0, 0)')
    expect(row(3)?.querySelector<HTMLElement>('.v-chip')?.style.color).toBe('rgb(255, 255, 255)')
    expect(document.querySelector('[data-test="edit-3"]')).not.toBeNull()
  })

  it('explains tags when there are none', async () => {
    base({
      'GET /workspaces/acme/tags': json(page([])),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => document.body.textContent).toContain('No tags yet')
    expect(document.body.textContent).toContain('filter the monitor list by tag')
  })

  it('creates a tag with a preset color', async () => {
    const server = base({
      'GET /workspaces/acme/tags': json(page([prod])),
      'POST /workspaces/acme/tags': json({
        data: { id: 9, name: 'staging', color: '#16a34a' },
      }, 201),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => row(3)).not.toBeNull()

    fill('input[name="tag_name"]', 'staging')
    await flushPromises()
    await click('[data-test="tag_color-green"]')
    await flushPromises()
    expect(document.querySelector<HTMLInputElement>('input[name="tag_color"]')?.value).toBe('#16a34a')
    await submit('input[name="tag_name"]')

    await expect.poll(() => row(9)).not.toBeNull()
    expect(server.of('POST /workspaces/acme/tags')[0]?.body).toEqual({ name: 'staging', color: '#16a34a' })
    expect(document.querySelector<HTMLInputElement>('input[name="tag_name"]')?.value).toBe('')
  })

  it('does not submit an invalid custom color', async () => {
    const server = base({
      'GET /workspaces/acme/tags': json(page([])),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => document.querySelector('input[name="tag_name"]')).not.toBeNull()

    fill('input[name="tag_name"]', 'staging')
    await flushPromises()
    fill('input[name="tag_color"]', '#12345')
    await flushPromises()
    await submit('input[name="tag_name"]')
    await flushPromises()

    expect(document.body.textContent).toContain('Enter the color as #rrggbb')
    expect(server.of('POST /workspaces/acme/tags')).toHaveLength(0)
  })

  it('shows a 422 on the name field', async () => {
    base({
      'GET /workspaces/acme/tags': json(page([prod])),
      'POST /workspaces/acme/tags': problem(422, {
        title: 'The given data was invalid.',
        errors: {
          name: ['The name has already been taken.'],
        },
      }),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => row(3)).not.toBeNull()

    fill('input[name="tag_name"]', 'prod')
    await submit('input[name="tag_name"]')

    await expect.poll(() => document.querySelector('input[name="tag_name"]')?.closest('.v-input')?.textContent).toContain('The name has already been taken.')
  })

  it('renames and recolors a tag with one PATCH', async () => {
    const server = base({
      'GET /workspaces/acme/tags': json(page([prod])),
      'PATCH /workspaces/acme/tags/3': json({
        data: { id: 3, name: 'production', color: '#0d9488' },
      }),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => row(3)).not.toBeNull()

    await click('[data-test="edit-3"]')
    await expect.poll(() => document.querySelector('input[name="edit_tag_name"]')).not.toBeNull()
    expect(document.querySelector<HTMLInputElement>('input[name="edit_tag_name"]')?.value).toBe('prod')
    fill('input[name="edit_tag_name"]', 'production')
    await flushPromises()
    fill('input[name="edit_tag_color"]', '#0D9488')
    await flushPromises()
    await submit('input[name="edit_tag_name"]')

    await expect.poll(() => server.of('PATCH /workspaces/acme/tags/3')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/tags/3')[0]?.body).toEqual({ name: 'production', color: '#0d9488' })
    await expect.poll(() => row(3)?.textContent).toContain('production')
  })

  it('shows a 422 of the edit on the field and keeps the dialog open', async () => {
    base({
      'GET /workspaces/acme/tags': json(page([prod, eu])),
      'PATCH /workspaces/acme/tags/3': problem(422, {
        title: 'The given data was invalid.',
        errors: {
          name: ['The name has already been taken.'],
        },
      }),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => row(3)).not.toBeNull()

    await click('[data-test="edit-3"]')
    await expect.poll(() => document.querySelector('input[name="edit_tag_name"]')).not.toBeNull()
    fill('input[name="edit_tag_name"]', 'eu-west')
    await submit('input[name="edit_tag_name"]')

    await expect.poll(() => document.querySelector('input[name="edit_tag_name"]')?.closest('.v-input')?.textContent).toContain('The name has already been taken.')
    expect(row(3)?.textContent).toContain('prod')
  })

  it('deletes a tag after a confirmation that monitors keep working', async () => {
    const server = base({
      'GET /workspaces/acme/tags': json(page([prod, eu])),
      'DELETE /workspaces/acme/tags/3': json(null, 204),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => row(3)).not.toBeNull()

    await click('[data-test="delete-3"]')
    await expect.poll(() => document.body.textContent).toContain('Delete the tag?')
    expect(document.body.textContent).toContain('The monitors keep running and alerting')
    expect(server.of('DELETE /workspaces/acme/tags/3')).toHaveLength(0)
    await click('[data-test="confirm"]')

    await expect.poll(() => server.of('DELETE /workspaces/acme/tags/3')).toHaveLength(1)
    await expect.poll(() => row(3)).toBeNull()
    expect(row(4)).not.toBeNull()
  })

  it('shows the list read-only to a viewer', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me({
          workspaces: [
            workspace({ role: 'viewer', can: viewerCan }),
          ],
        }),
      }),
      'GET /workspaces/acme/tags': json(page([prod])),
    })
    await mountApp('/w/acme/tags')
    await expect.poll(() => row(3)).not.toBeNull()

    expect(document.querySelector('[data-test="tags-read-only"]')).not.toBeNull()
    expect(document.querySelector('input[name="tag_name"]')).toBeNull()
    expect(document.querySelector('[data-test="edit-3"]')).toBeNull()
    expect(document.querySelector('[data-test="delete-3"]')).toBeNull()
    // The menu still offers the page to whoever can view monitors.
    expect(document.querySelector('a[href="/w/acme/tags"]')).not.toBeNull()
  })
})
