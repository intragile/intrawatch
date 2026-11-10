import type { MonitorTypeInfo } from '../../../../api/types'
import { describe, expect, it } from 'vitest'
import { newForm } from '../model'

const info = {
  type: 'tls',
  default_interval_seconds: 3600,
  defaults: {},
} as unknown as MonitorTypeInfo

describe('newForm', () => {
  // The backend defaults notify_changes to true; a false here silently turned
  // off certificate, DNS and expiry alerts on every monitor made in the SPA.
  it('turns change alerts on, like the backend default', () => {
    expect(newForm(info).alerting.notify_changes).toBe(true)
  })
})
