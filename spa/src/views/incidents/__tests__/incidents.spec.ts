import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { meWith, viewerCan } from '../../../test/fixtures'
import { click, fill, mountApp } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import { choose, page } from '../../workspace/__tests__/helpers'

const text = () => document.body.textContent ?? ''

function incident(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    title: 'Checkout is slow',
    impact: 'major',
    status: 'investigating',
    started_at: '2026-01-01T10:00:00+00:00',
    resolved_at: null,
    monitor_ids: [],
    status_page_ids: [],
    created_at: '2026-01-01T10:00:00+00:00',
    updated_at: '2026-01-01T10:00:00+00:00',
    updates: [
      {
        id: 1,
        status: 'investigating',
        message: 'We are looking into it.',
        created_at: '2026-01-01T10:00:00+00:00',
        updated_at: null,
      },
    ],
    ...overrides,
  }
}

describe('incident page', () => {
  it('posting an update with resolved status moves the incident to resolved', async () => {
    const resolved = incident({
      status: 'resolved',
      resolved_at: '2026-01-01T11:00:00+00:00',
      updates: [
        {
          id: 2,
          status: 'resolved',
          message: 'Fixed by a rollback.',
          created_at: '2026-01-01T11:00:00+00:00',
          updated_at: null,
        },
        ...incident().updates,
      ],
    })
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/incidents/5': [
        json({
          data: incident(),
        }),
        json({ data: resolved }),
      ],
      'POST /workspaces/acme/incidents/5/updates': json({ data: resolved.updates[0] }, 201),
    })
    const { wrapper } = await mountApp('/w/acme/incidents/5')
    await expect.poll(() => document.querySelector('[data-test="update-status"]')).not.toBeNull()
    expect(document.querySelector('[data-test="incident-status"]')?.textContent).toContain('Investigating')

    choose(wrapper, 'update-status', 'resolved')
    await flushPromises()
    fill('textarea[name="update_message"]', 'Fixed by a rollback.')
    await click('[data-test="update-post"]')

    await expect.poll(() => document.querySelector('[data-test="incident-status"]')?.textContent).toContain('Resolved')
    expect(server.of('POST /workspaces/acme/incidents/5/updates')[0]?.body).toEqual({
      status: 'resolved',
      message: 'Fixed by a rollback.',
    })
    expect(text()).toContain('Fixed by a rollback.')
    expect(text()).toContain('We are looking into it.')
  })

  it('lists the timeline newest first without a composer for a viewer', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ can: viewerCan }),
      }),
      'GET /workspaces/acme/incidents/5': json({
        data: incident({
          updates: [
            {
              id: 2,
              status: 'monitoring',
              message: 'Newest entry',
              created_at: '2026-01-01T11:00:00+00:00',
              updated_at: null,
            },
            {
              id: 1,
              status: 'investigating',
              message: 'Oldest entry',
              created_at: '2026-01-01T10:00:00+00:00',
              updated_at: null,
            },
          ],
        }),
      }),
    })
    await mountApp('/w/acme/incidents/5')
    await expect.poll(() => text()).toContain('Newest entry')

    expect(text().indexOf('Newest entry')).toBeLessThan(text().indexOf('Oldest entry'))
    expect(document.querySelector('[data-test="update-post"]')).toBeNull()
    expect(document.querySelector('[data-test="incident-delete"]')).toBeNull()
  })

  it('saves only the changed incident details', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/incidents/5': json({
        data: incident(),
      }),
      'PATCH /workspaces/acme/incidents/5': json({
        data: {
          ...incident({ impact: 'critical' }),
          updates: undefined,
        },
      }),
    })
    const { wrapper } = await mountApp('/w/acme/incidents/5')
    await expect.poll(() => document.querySelector('[data-test="incident-impact"]')).not.toBeNull()

    choose(wrapper, 'incident-impact', 'critical')
    await click('[data-test="incident-save"]')

    await expect.poll(() => server.of('PATCH /workspaces/acme/incidents/5')).toHaveLength(1)
    expect(server.of('PATCH /workspaces/acme/incidents/5')[0]?.body).toEqual({ impact: 'critical' })
  })

  it('refetches the open incident when the window regains focus', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/incidents/5': [
        json({
          data: incident(),
        }),
        json({
          data: incident({ status: 'monitoring' }),
        }),
      ],
    })
    await mountApp('/w/acme/incidents/5')
    await expect.poll(() => document.querySelector('[data-test="incident-status"]')).not.toBeNull()

    window.dispatchEvent(new Event('focus'))

    await expect.poll(() => server.of('GET /workspaces/acme/incidents/5')).toHaveLength(2)
    await expect.poll(() => document.querySelector('[data-test="incident-status"]')?.textContent).toContain('Monitoring')
  })

  it('warns when the timeline entries cannot be read', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/incidents/5': json({
        data: incident({
          updates: ['oops'],
        }),
      }),
    })
    await mountApp('/w/acme/incidents/5')

    await expect.poll(() => document.querySelector('[data-test="timeline-unreadable"]')).not.toBeNull()
  })

  it('refetches the loaded incident list when the window regains focus', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/incidents': json(page([])),
    })
    await mountApp('/w/acme/incidents')
    await expect.poll(() => server.of('GET /workspaces/acme/incidents')).toHaveLength(1)

    window.dispatchEvent(new Event('focus'))

    await expect.poll(() => server.of('GET /workspaces/acme/incidents')).toHaveLength(2)
  })

  it('shows a failed monitor lookup and the status page truncation hint', async () => {
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({}),
      }),
      'GET /workspaces/acme/incidents/5': json({
        data: incident({
          monitor_ids: [9],
        }),
      }),
      'GET /workspaces/acme/monitors/9': problem(500, { title: 'Monitor lookup failed' }),
      'GET /workspaces/acme/status-pages': json({
        data: [],
        meta: { next_cursor: 'more', limit: 100 },
      }),
    })
    await mountApp('/w/acme/incidents/5')

    await expect.poll(() => text()).toContain('Monitor lookup failed')
    await expect.poll(() => text()).toContain('Only the first 500 status pages are listed.')
  })
})
