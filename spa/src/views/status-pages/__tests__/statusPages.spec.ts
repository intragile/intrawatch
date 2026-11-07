import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { abilities, meWith } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import { choose } from '../../workspace/__tests__/helpers'

const text = () => document.body.textContent ?? ''

function item(id: number, monitorId: number, overrides: Record<string, unknown> = {}) {
  return {
    id,
    monitor_id: monitorId,
    display_name: null,
    description: null,
    position: 0,
    expand_children: false,
    show_uptime: true,
    ...overrides,
  }
}

function statusPage(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    slug: 'acme-status',
    title: 'Acme status',
    description: null,
    custom_domain: null,
    published: true,
    locale: 'en',
    accent_color: null,
    logo_url: null,
    favicon_url: null,
    custom_css: null,
    footer: null,
    bar_days: 90,
    uptime_days: 30,
    upcoming_maintenance_days: 14,
    incident_history_days: 7,
    show_latency: true,
    created_at: null,
    updated_at: null,
    sections: [
      {
        id: 1,
        name: 'Core',
        description: null,
        position: 0,
        items: [
          item(11, 1),
          item(12, 2, { display_name: 'Checkout' }),
        ],
      },
      {
        id: 2,
        name: 'Extras',
        description: null,
        position: 1,
        items: [
          item(13, 3),
        ],
      },
    ],
    ...overrides,
  }
}

function base(can?: Partial<ReturnType<typeof abilities>>) {
  return {
    'GET /setup': json({ required: false }),
    'GET /me': json({
      data: meWith(can === undefined
        ? {}
        : {
            can: abilities(true, can),
          }),
    }),
  }
}

describe('status page editor', () => {
  it('layout save sends sections and items in display order', async () => {
    const server = fakeApi({
      ...base(),
      'GET /workspaces/acme/status-pages/5': json({
        data: statusPage(),
      }),
      'PUT /workspaces/acme/status-pages/5/layout': json({
        data: statusPage(),
      }),
    })
    const { wrapper } = await mountApp('/w/acme/status-pages/5')
    await expect.poll(() => document.querySelector('input[name="section_name_0"]')).not.toBeNull()

    await click('[data-test="section-add"]')
    await expect.poll(() => document.querySelector('input[name="section_name_2"]')).not.toBeNull()
    fill('input[name="section_name_2"]', 'Third party')
    choose(wrapper, 'item-picker-2', { id: 9, name: 'Payments' })
    await flushPromises()
    await click('[data-test="layout-save"]')

    await expect.poll(() => server.of('PUT /workspaces/acme/status-pages/5/layout')).toHaveLength(1)
    expect(server.of('PUT /workspaces/acme/status-pages/5/layout')[0]?.body).toEqual({
      sections: [
        {
          name: 'Core',
          description: null,
          items: [
            {
              monitor_id: 1,
              display_name: null,
              description: null,
              expand_children: false,
              show_uptime: true,
            },
            {
              monitor_id: 2,
              display_name: 'Checkout',
              description: null,
              expand_children: false,
              show_uptime: true,
            },
          ],
        },
        {
          name: 'Extras',
          description: null,
          items: [
            {
              monitor_id: 3,
              display_name: null,
              description: null,
              expand_children: false,
              show_uptime: true,
            },
          ],
        },
        {
          name: 'Third party',
          description: null,
          items: [
            {
              monitor_id: 9,
              display_name: null,
              description: null,
              expand_children: false,
              show_uptime: true,
            },
          ],
        },
      ],
    })
  })

  it('reordering with keyboard buttons changes the PUT order', async () => {
    const server = fakeApi({
      ...base(),
      'GET /workspaces/acme/status-pages/5': json({
        data: statusPage(),
      }),
      'PUT /workspaces/acme/status-pages/5/layout': json({
        data: statusPage(),
      }),
    })
    await mountApp('/w/acme/status-pages/5')
    await expect.poll(() => document.querySelector('[data-test="item-down-0-0"]')).not.toBeNull()

    await click('[data-test="item-down-0-0"]')
    await click('[data-test="section-down-0"]')
    await click('[data-test="layout-save"]')

    await expect.poll(() => server.of('PUT /workspaces/acme/status-pages/5/layout')).toHaveLength(1)
    const body = server.of('PUT /workspaces/acme/status-pages/5/layout')[0]?.body as {
      sections: Array<{ name: string, items: Array<{ monitor_id: number }> }>
    }
    expect(body.sections.map(s => s.name)).toEqual(['Extras', 'Core'])
    expect(body.sections[1]?.items.map(i => i.monitor_id)).toEqual([2, 1])
  })

  it('custom domain field hidden without manageCustomDomains', async () => {
    fakeApi({
      ...base({ manageCustomDomains: false }),
      'GET /workspaces/acme/status-pages/5': json({
        data: statusPage(),
      }),
    })
    await mountApp('/w/acme/status-pages/5')
    await expect.poll(() => document.querySelector('input[name="status_title"]')).not.toBeNull()

    expect(document.querySelector('input[name="status_custom_domain"]')).toBeNull()
  })

  it('422 for app host lands on the custom domain field', async () => {
    const server = fakeApi({
      ...base(),
      'GET /workspaces/acme/status-pages/5': json({
        data: statusPage(),
      }),
      'PATCH /workspaces/acme/status-pages/5': problem(422, {
        title: 'The given data was invalid.',
        errors: {
          custom_domain: ['The custom domain must not be the host of this installation.'],
        },
      }),
    })
    await mountApp('/w/acme/status-pages/5')
    await expect.poll(() => document.querySelector('input[name="status_custom_domain"]')).not.toBeNull()

    fill('input[name="status_custom_domain"]', 'intrawatch.example.com')
    await submit('input[name="status_title"]')

    await expect.poll(() => document.querySelector('input[name="status_custom_domain"]')?.closest('.v-input')?.textContent)
      .toContain('must not be the host of this installation')
    expect(server.of('PATCH /workspaces/acme/status-pages/5')[0]?.body).toEqual({
      custom_domain: 'intrawatch.example.com',
    })
  })

  it('public link uses the slug', async () => {
    fakeApi({
      ...base(),
      'GET /workspaces/acme/status-pages/5': json({
        data: statusPage({ custom_domain: 'status.acme.test' }),
      }),
    })
    await mountApp('/w/acme/status-pages/5')
    await expect.poll(() => document.querySelector('[data-test="public-link"]')).not.toBeNull()

    const link = document.querySelector<HTMLAnchorElement>('[data-test="public-link"]')
    expect(link?.getAttribute('href')).toBe('/status/acme-status')
    expect(link?.getAttribute('target')).toBe('_blank')
    expect(document.querySelector('[data-test="custom-domain-link"]')?.getAttribute('href'))
      .toBe('https://status.acme.test/')
    expect(text()).toContain('up to 60 s')
  })
})
