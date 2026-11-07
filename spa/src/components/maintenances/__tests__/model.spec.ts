import { describe, expect, it } from 'vitest'
import { createBody, newMaintenanceForm, patchBody } from '../model'

describe('maintenance form model', () => {
  it('sends a one-off window as UTC instants for the chosen zone', () => {
    const form = {
      ...newMaintenanceForm('Europe/Budapest'),
      title: 'DB upgrade',
      starts_at: '2026-07-01T12:00',
      ends_at: '',
      monitor_ids: [2, 1],
    }

    expect(createBody(form)).toEqual({
      title: 'DB upgrade',
      description: null,
      strategy: 'once',
      timezone: 'Europe/Budapest',
      active: true,
      monitor_ids: [1, 2],
      status_page_ids: [],
      starts_at: '2026-07-01T10:00:00.000Z',
      ends_at: null,
    })
  })

  it('patches only the changed keys, and all strategy fields when the strategy changes', () => {
    const initial = {
      ...newMaintenanceForm('UTC'),
      title: 'Weekly',
      strategy: 'weekly' as const,
      weekdays: [1],
      monitor_ids: [1],
    }

    expect(patchBody(initial, { ...initial, title: 'Weekly patching' })).toEqual({ title: 'Weekly patching' })
    expect(patchBody(initial, {
      ...initial,
      weekdays: [3, 1],
    })).toEqual({
      weekdays: [1, 3],
    })
    expect(patchBody(initial, { ...initial, strategy: 'cron', cron: '0 3 * * *' })).toEqual({
      title: 'Weekly',
      description: null,
      strategy: 'cron',
      timezone: 'UTC',
      active: true,
      monitor_ids: [1],
      status_page_ids: [],
      cron: '0 3 * * *',
      duration_minutes: 60,
      valid_from: null,
      valid_until: null,
    })
  })
})
