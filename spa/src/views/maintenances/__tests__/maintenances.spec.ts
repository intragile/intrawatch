import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { meWith, viewerCan } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'
import { choose, page } from '../../workspace/__tests__/helpers'

const text = () => document.body.textContent ?? ''

function maintenance(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    title: 'Database upgrade',
    description: null,
    strategy: 'once',
    timezone: 'Europe/Budapest',
    starts_at: '2026-01-01T09:00:00+00:00',
    ends_at: '2026-01-01T12:00:00+00:00',
    start_time: null,
    duration_minutes: null,
    every_days: null,
    weekdays: null,
    month_days: null,
    cron: null,
    valid_from: null,
    valid_until: null,
    active: true,
    monitor_ids: [],
    status_page_ids: [],
    in_progress: true,
    next_window: {
      starts_at: '2026-01-01T09:00:00+00:00',
      ends_at: '2026-01-01T12:00:00+00:00',
    },
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function base(can?: Parameters<typeof meWith>[0]) {
  return {
    'GET /setup': json({ required: false }),
    'GET /me': json({
      data: meWith(can ?? {}),
    }),
  }
}

describe('maintenance windows', () => {
  it('maintenance list filters by state via the API', async () => {
    const urls: string[] = []
    fakeApi({
      ...base(),
      'GET /workspaces/acme/maintenances': (req) => {
        urls.push(req.url)
        return json(page([
          maintenance(),
        ]))
      },
    })
    await mountApp('/w/acme/maintenances')
    await expect.poll(() => urls.length).toBe(1)
    expect(new URL(urls[0] ?? '').searchParams.get('state')).toBeNull()

    await click('[data-test="state-upcoming"]')

    await expect.poll(() => urls.length).toBe(2)
    expect(new URL(urls[1] ?? '').searchParams.get('state')).toBe('upcoming')
  })

  it('recurring cron strategy sends the documented fields', async () => {
    const server = fakeApi({
      ...base(),
      'POST /workspaces/acme/maintenances': json({
        data: maintenance({ id: 11, strategy: 'cron' }),
      }, 201),
      'GET /workspaces/acme/maintenances': json(page([])),
    })
    const { wrapper } = await mountApp('/w/acme/maintenances/new')
    await expect.poll(() => document.querySelector('[data-test="maintenance-strategy"]')).not.toBeNull()
    choose(wrapper, 'maintenance-strategy', 'cron')
    await flushPromises()

    fill('input[name="maintenance_title"]', 'Nightly patch')
    fill('input[name="maintenance_cron"]', '0 3 * * 1')
    fill('input[name="maintenance_duration"]', '30')
    choose(wrapper, 'affected-monitors', [3])
    await submit('input[name="maintenance_title"]')

    await expect.poll(() => server.of('POST /workspaces/acme/maintenances')).toHaveLength(1)
    expect(server.of('POST /workspaces/acme/maintenances')[0]?.body).toEqual({
      title: 'Nightly patch',
      description: null,
      strategy: 'cron',
      timezone: 'Europe/Budapest',
      cron: '0 3 * * 1',
      duration_minutes: 30,
      valid_from: null,
      valid_until: null,
      active: true,
      monitor_ids: [3],
      status_page_ids: [],
    })
  })

  it('end now asks and refreshes', async () => {
    const server = fakeApi({
      ...base(),
      'GET /workspaces/acme/maintenances': [
        json(page([
          maintenance(),
        ])),
        json(page([])),
      ],
      'POST /workspaces/acme/maintenances/7/end': json({
        data: maintenance({ in_progress: false, next_window: null }),
      }),
    })
    await mountApp('/w/acme/maintenances')
    await expect.poll(() => text()).toContain('Database upgrade')

    await click('[data-test="maintenance-end-7"]')
    await expect.poll(() => document.querySelector('[data-test="confirm"]')).not.toBeNull()
    expect(server.of('POST /workspaces/acme/maintenances/7/end')).toHaveLength(0)

    await click('[data-test="confirm"]')

    await expect.poll(() => server.of('POST /workspaces/acme/maintenances/7/end')).toHaveLength(1)
    await expect.poll(() => server.of('GET /workspaces/acme/maintenances')).toHaveLength(2)
    await expect.poll(() => text()).not.toContain('Database upgrade')
  })

  it('editor without incidents:write is read-only', async () => {
    fakeApi({
      ...base({ can: viewerCan }),
      'GET /workspaces/acme/maintenances/7': json({
        data: maintenance(),
      }),
    })
    await mountApp('/w/acme/maintenances/7')
    await expect.poll(() => document.querySelector('input[name="maintenance_title"]')).not.toBeNull()

    expect(document.querySelector<HTMLInputElement>('input[name="maintenance_title"]')?.disabled).toBe(true)
    expect(document.querySelector('[data-test="maintenance-save"]')).toBeNull()
    expect(document.querySelector('[data-test="maintenance-end-now"]')).toBeNull()
    expect(text()).toContain('read-only')
  })
})
