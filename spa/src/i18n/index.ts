import { createI18n } from 'vue-i18n'
import { readPreference, writePreference } from '../utils/storage'
import en from './en.json'
import hu from './hu.json'

export type MessageSchema = typeof en
export const LOCALES = ['en', 'hu'] as const
export type Locale = typeof LOCALES[number]

const STORAGE_KEY = 'intrawatch.locale'

export function isLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (LOCALES as readonly string[]).includes(value)
}

/** The stored choice, otherwise the browser language: any `hu*` gives Hungarian. */
export function detectLocale(): Locale {
  const stored = readPreference(STORAGE_KEY)
  if (isLocale(stored))
    return stored
  const languages = typeof navigator === 'undefined'
    ? []
    : (navigator.languages ?? [navigator.language])
  return languages.some(l => l?.toLowerCase().startsWith('hu'))
    ? 'hu'
    : 'en'
}

export const i18n = createI18n<[MessageSchema], Locale, false>({
  legacy: false,
  locale: detectLocale(),
  fallbackLocale: 'en',
  messages: { en, hu },
})

export function applyLocale(locale: Locale, persist = true): void {
  i18n.global.locale.value = locale
  if (persist)
    writePreference(STORAGE_KEY, locale)
  if (typeof document !== 'undefined')
    document.documentElement.lang = locale
}
