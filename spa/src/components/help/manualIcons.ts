import {
  mdiAccountGroup,
  mdiAccountMinus,
  mdiArrowDown,
  mdiArrowUp,
  mdiBellOutline,
  mdiCheck,
  mdiCheckboxMultipleMarkedOutline,
  mdiClockAlertOutline,
  mdiClose,
  mdiCogOutline,
  mdiContentCopy,
  mdiDatabaseImportOutline,
  mdiDeleteOutline,
  mdiDownload,
  mdiDragVertical,
  mdiEmailRemove,
  mdiFileDocumentOutline,
  mdiHistory,
  mdiInformationOutline,
  mdiKeyChange,
  mdiKeyRemove,
  mdiKeyVariant,
  mdiLogout,
  mdiMonitorDashboard,
  mdiOpenInNew,
  mdiPause,
  mdiPencil,
  mdiPlay,
  mdiPlus,
  mdiRefresh,
  mdiSend,
  mdiShieldAccountOutline,
  mdiShieldCheck,
  mdiShieldOffOutline,
  mdiStopCircleOutline,
  mdiTrashCanOutline,
  mdiWeatherNight,
  mdiWeatherSunny,
  mdiWebCheck,
} from '@mdi/js'

/**
 * The icons the manual content and `resources/manual/_meta.php` name, in their `mdi-` kebab form.
 * Only these are bundled; a spec fails when the content names one that is missing here.
 */
const ICONS: Readonly<Record<string, string>> = {
  'mdi-account-group': mdiAccountGroup,
  'mdi-account-minus': mdiAccountMinus,
  'mdi-arrow-down': mdiArrowDown,
  'mdi-arrow-up': mdiArrowUp,
  'mdi-bell-outline': mdiBellOutline,
  'mdi-check': mdiCheck,
  'mdi-checkbox-multiple-marked-outline': mdiCheckboxMultipleMarkedOutline,
  'mdi-clock-alert-outline': mdiClockAlertOutline,
  'mdi-close': mdiClose,
  'mdi-cog-outline': mdiCogOutline,
  'mdi-content-copy': mdiContentCopy,
  'mdi-database-import-outline': mdiDatabaseImportOutline,
  'mdi-delete-outline': mdiDeleteOutline,
  'mdi-download': mdiDownload,
  'mdi-drag-vertical': mdiDragVertical,
  'mdi-email-remove': mdiEmailRemove,
  'mdi-file-document-outline': mdiFileDocumentOutline,
  'mdi-history': mdiHistory,
  'mdi-information-outline': mdiInformationOutline,
  'mdi-key-change': mdiKeyChange,
  'mdi-key-remove': mdiKeyRemove,
  'mdi-key-variant': mdiKeyVariant,
  'mdi-logout': mdiLogout,
  'mdi-monitor-dashboard': mdiMonitorDashboard,
  'mdi-open-in-new': mdiOpenInNew,
  'mdi-pause': mdiPause,
  'mdi-pencil': mdiPencil,
  'mdi-play': mdiPlay,
  'mdi-plus': mdiPlus,
  'mdi-refresh': mdiRefresh,
  'mdi-send': mdiSend,
  'mdi-shield-account-outline': mdiShieldAccountOutline,
  'mdi-shield-check': mdiShieldCheck,
  'mdi-shield-off-outline': mdiShieldOffOutline,
  'mdi-stop-circle-outline': mdiStopCircleOutline,
  'mdi-trash-can-outline': mdiTrashCanOutline,
  'mdi-weather-night': mdiWeatherNight,
  'mdi-weather-sunny': mdiWeatherSunny,
  'mdi-web-check': mdiWebCheck,
}

/** The SVG path of a manual icon name, or null for a name the map does not know. */
export function manualIcon(name: string | null | undefined): string | null {
  if (!name || !Object.hasOwn(ICONS, name))
    return null
  return ICONS[name] ?? null
}

/** Every name the map knows (for the content coverage spec). */
export const MANUAL_ICON_NAMES: readonly string[] = Object.keys(ICONS)
