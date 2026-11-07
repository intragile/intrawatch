import { createI18n } from 'vue-i18n'
import { readPreference, writePreference } from '../utils/storage'

export type MessageSchema = typeof import('./en.json')
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

/** Every locale is a separate chunk fetched the first time it is needed. */
const LOADERS: Record<Locale, () => Promise<MessageSchema>> = {
  en: async () => (await import('./en.json')).default,
  hu: async () => (await import('./hu.json')).default,
}

export const i18n = createI18n({
  legacy: false,
  locale: detectLocale(),
  fallbackLocale: 'en' satisfies Locale,
  messages: {},
})

const loaded = new Set<Locale>()
let requested: Locale | null = null

/** The locale that backs missing keys of the active one. */
export const FALLBACK_LOCALE: Locale = 'en'

/** Makes the messages of a locale available, fetching them on first use. */
export async function loadLocaleMessages(locale: Locale): Promise<void> {
  if (loaded.has(locale))
    return
  i18n.global.setLocaleMessage(locale, await LOADERS[locale]())
  loaded.add(locale)
}

/**
 * Switches to a locale once its messages are loaded. When switches overlap, the last one requested wins.
 */
export async function applyLocale(locale: Locale, persist = true): Promise<void> {
  requested = locale
  await loadLocaleMessages(locale)
  if (requested !== locale)
    return
  i18n.global.locale.value = locale
  if (persist)
    writePreference(STORAGE_KEY, locale)
  if (typeof document !== 'undefined')
    document.documentElement.lang = locale
}
