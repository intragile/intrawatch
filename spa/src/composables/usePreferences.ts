import type { Locale } from '../i18n'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLocale, useTheme } from 'vuetify'
import { applyLocale, LOCALES } from '../i18n'
import { writePreference } from '../utils/storage'

export const THEME_KEY = 'intrawatch.theme'

/** Theme and language switches shared by both layouts. */
export function usePreferences() {
  const theme = useTheme()
  const vuetifyLocale = useLocale()
  const { locale } = useI18n()

  const isDark = computed(() => theme.global.current.value.dark)

  function toggleTheme(): void {
    const next = isDark.value ? 'light' : 'dark'
    theme.change(next)
    writePreference(THEME_KEY, next)
  }

  function setLocale(next: Locale): void {
    applyLocale(next)
    vuetifyLocale.current.value = next
  }

  return {
    isDark,
    toggleTheme,
    locale: computed(() => locale.value as Locale),
    locales: LOCALES,
    setLocale,
  }
}
