import { createVuetify } from 'vuetify'
import { aliases, mdi } from 'vuetify/iconsets/mdi-svg'
import { en, hu } from 'vuetify/locale'
import { THEME_KEY } from '../composables/usePreferences'
import { detectLocale } from '../i18n'
import { readPreference } from '../utils/storage'
import 'vuetify/styles'

const primary = '#0B6E4F'

function initialTheme(): 'light' | 'dark' {
  const stored = readPreference(THEME_KEY)
  if (stored === 'light' || stored === 'dark')
    return stored
  const prefersDark = typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-color-scheme: dark)').matches
  return prefersDark ? 'dark' : 'light'
}

export default createVuetify({
  icons: {
    defaultSet: 'mdi',
    aliases,
    sets: { mdi },
  },
  locale: {
    locale: detectLocale(),
    fallback: 'en',
    messages: { en, hu },
  },
  theme: {
    defaultTheme: initialTheme(),
    themes: {
      light: {
        dark: false,
        colors: { primary },
      },
      dark: {
        dark: true,
        colors: { primary: '#3FB68B' },
      },
    },
  },
})
