import { describe, expect, it } from 'vitest'
import { me } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'
import { choose, page } from './helpers'

function event(id: number, action: string) {
  return {
    id,
    action,
    actor_type: 'user',
    actor_id: 1,
    actor_user_id: 1,
    subject_type: 'monitor',
    subject_id: id,
    changes: {
      name: ['Old', 'New'],
    },
    ip: '203.0.113.9',
    created_at: '2026-01-01T10:00:00+00:00',
  }
}

describe('audit log', () => {
  it('audit list loads more by cursor', async () => {
    const urls: string[] = []
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me(),
      }),
      'GET /workspaces/acme/audit': (req) => {
        urls.push(req.url)
        return new URL(req.url).searchParams.get('cursor') === 'c2'
          ? json(page([
              event(1, 'monitor.deleted'),
            ]))
          : json(page([
              event(2, 'monitor.created'),
            ], 'c2'))
      },
    })
    await mountApp('/w/acme/audit')
    await expect.poll(() => document.body.textContent).toContain('monitor.created')
    expect(document.body.textContent).not.toContain('monitor.deleted')

    await click('[data-test="load-more"]')

    await expect.poll(() => document.body.textContent).toContain('monitor.deleted')
    expect(document.body.textContent).toContain('monitor.created')
    expect(urls).toHaveLength(2)
    expect(new URL(urls[1] as string).searchParams.get('cursor')).toBe('c2')
    await expect.poll(() => document.querySelector('[data-test="load-more"]')).toBeNull()
  })

  it('sends the documented filters and restarts the list', async () => {
    const urls: string[] = []
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me(),
      }),
      'GET /workspaces/acme/audit': (req) => {
        urls.push(req.url)
        return json(page([
          event(1, 'token.created'),
        ], 'c2'))
      },
    })
    const { wrapper } = await mountApp('/w/acme/audit')
    await expect.poll(() => urls).toHaveLength(1)

    fill('input[name="audit_action"]', 'token.created')
    choose(wrapper, 'filter-actor-type', 'token')
    await submit('input[name="audit_action"]')

    await expect.poll(() => urls).toHaveLength(2)
    const params = new URL(urls[1] as string).searchParams
    expect(params.get('action')).toBe('token.created')
    expect(params.get('actor_type')).toBe('token')
    expect(params.get('cursor')).toBeNull()
  })
})
