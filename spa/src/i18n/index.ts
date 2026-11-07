import { createI18n } from 'vue-i18n'
import { readPreference, writePreference } from '../utils/storage'
import en from './en.json'

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

/**
 * English ships in the main bundle as the fallback; every other locale is a separate chunk fetched the
 * first time it is applied.
 */
const LOADERS: Record<Locale, () => Promise<MessageSchema>> = {
  en: async () => en,
  hu: async () => (await import('./hu.json')).default,
}

/** Only English is present up front; the rest are added by loadLocaleMessages(). */
const initialMessages: Partial<Record<Locale, MessageSchema>> = { en }

export const i18n = createI18n({
  legacy: false,
  locale: detectLocale(),
  fallbackLocale: 'en',
  messages: initialMessages,
})

const loaded = new Set<Locale>(['en'])
let requested: Locale | null = null

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
