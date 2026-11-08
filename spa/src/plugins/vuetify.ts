import { createVuetify } from 'vuetify'
import { aliases, mdi } from 'vuetify/iconsets/mdi-svg'
import { en, hu } from 'vuetify/locale'
import { THEME_KEY } from '../composables/usePreferences'
import { detectLocale } from '../i18n'
import { readPreference } from '../utils/storage'
import '@fontsource/barlow/400.css'
import '@fontsource/barlow/500.css'
import '@fontsource/barlow/600.css'
import '@fontsource/barlow-semi-condensed/500.css'
import '@fontsource/barlow-semi-condensed/600.css'
import 'vuetify/styles'
import '../styles/app.css'

function initialTheme(): 'light' | 'dark' {
  const stored = readPreference(THEME_KEY)
  if (stored === 'light' || stored === 'dark')
    return stored
  const prefersDark = typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-color-scheme: dark)').matches
  return prefersDark ? 'dark' : 'light'
}

// Theme colours. Status colours are the text-safe shades (4.5:1 on surface); the brighter fills used by
// heartbeat strips and status marks live in styles/app.css as --iw-* custom properties.
const light = {
  'background': '#E8ECF0',
  'surface': '#FFFFFF',
  'surface-light': '#F2F4F7',
  'surface-variant': '#17202B',
  'on-surface-variant': '#E3E8EF',
  'primary': '#2346B5',
  'secondary': '#526070',
  'success': '#157F4D',
  'error': '#BF2D35',
  'warning': '#8C5B00',
  'info': '#1F64A8',
  'maintenance': '#6448B8',
  'idle': '#5F6A78',
}

const dark = {
  'background': '#111821',
  'surface': '#18212C',
  'surface-light': '#1E2833',
  'surface-variant': '#E3E8EF',
  'on-surface-variant': '#17202B',
  'primary': '#8FA7F4',
  'secondary': '#9AA6B6',
  'success': '#55C990',
  'error': '#F4878B',
  'warning': '#EBBB55',
  'info': '#7FB0E8',
  'maintenance': '#B19EF3',
  'idle': '#8D98A8',
}

const variables = {
  'border-color': '#526070',
  'border-opacity': 0.28,
  'medium-emphasis-opacity': 0.72,
}

const field = { variant: 'outlined' as const, density: 'comfortable' as const }

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
      light: { dark: false, colors: light, variables },
      dark: {
        dark: true,
        colors: dark,
        variables: { ...variables, 'border-color': '#9AA6B6', 'border-opacity': 0.22 },
      },
    },
  },
  defaults: {
    VCard: { variant: 'flat', border: true },
    VTextField: field,
    VTextarea: field,
    VSelect: field,
    VAutocomplete: field,
    VCombobox: field,
    VNumberInput: field,
    VFileInput: field,
    VChip: { label: true },
    VAlert: { variant: 'tonal' },
    VTabs: { color: 'primary' },
    VTable: { density: 'comfortable' },
  },
})
