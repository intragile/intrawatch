import { afterEach, describe, expect, it } from 'vitest'
import { applyLocale, i18n } from '..'
import hu from '../hu.json'

describe('locale loading', () => {
  afterEach(async () => {
    await applyLocale('en', false)
  })

  it('lets the last of overlapping switches win', async () => {
    const toHungarian = applyLocale('hu', false)
    const backToEnglish = applyLocale('en', false)
    await Promise.all([toHungarian, backToEnglish])

    expect(i18n.global.locale.value).toBe('en')
  })

  it('fetches the Hungarian messages when Hungarian is applied', async () => {
    await applyLocale('hu', false)

    expect(i18n.global.locale.value).toBe('hu')
    expect(i18n.global.t('common.cancel')).toBe(hu.common.cancel)
  })
})
