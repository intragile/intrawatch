import type { RouteLocationRaw } from 'vue-router'
import type { Ability } from '../../api/types'
import type { Locale } from '../../i18n'

/**
 * The location of a manual chapter, optionally at one of its heading anchors. Built as a path
 * because a `help.chapter` params object would encode the slash of `section/chapter` as `%2F`;
 * the path still resolves to that name.
 */
export function chapterLocation(slug: string, anchor?: string): RouteLocationRaw {
  const path = `/help/${slug.split('/').map(encodeURIComponent).join('/')}`
  return anchor
    ? { path, hash: `#${anchor}` }
    : { path }
}

/** The app screen a chapter describes: a route name and, for workspace routes, the ability the rail asks for. */
export interface AppTarget {
  route: string
  ability?: Ability
}

/**
 * Which screen "Open in the app" leads to from a chapter. Workspace (`ws.*`) routes use the same
 * abilities as the navigation rail, so the card is offered only where the rail would show the item.
 */
export const CHAPTER_APP_TARGETS: Readonly<Record<string, AppTarget>> = {
  'monitoring/monitors': { route: 'ws.monitors', ability: 'viewMonitors' },
  'monitoring/notifications': { route: 'ws.channels', ability: 'viewNotifications' },
  'monitoring/incidents': { route: 'ws.incidents', ability: 'viewIncidents' },
  'monitoring/maintenances': { route: 'ws.maintenances', ability: 'viewIncidents' },
  'monitoring/status-pages': { route: 'ws.status-pages', ability: 'viewStatusPages' },
  'workspace/workspace': { route: 'ws.members', ability: 'viewMembers' },
  'workspace/api-tokens': { route: 'ws.tokens', ability: 'view' },
  'workspace/import': { route: 'ws.import', ability: 'update' },
  'workspace/audit': { route: 'ws.audit', ability: 'viewAudit' },
  'account/account': { route: 'account' },
}

/** The app target of a chapter, or null when the chapter does not describe one screen. */
export function chapterAppTarget(slug: string): AppTarget | null {
  return Object.hasOwn(CHAPTER_APP_TARGETS, slug)
    ? CHAPTER_APP_TARGETS[slug] ?? null
    : null
}

/**
 * A heading anchor per locale. Anchors are slugified headings, and the headings are translated,
 * so the same section has a different anchor in every language.
 */
export type LocalizedAnchor = Readonly<Record<Locale, string>>

/** The manual chapter (and optionally the section of it) that explains a screen. */
export interface ScreenHelp {
  slug: string
  anchor?: LocalizedAnchor
}

/**
 * The help link of each app screen, by route name. Every screen a chapter opens ("Open in the
 * app") links back to the top of that chapter; the rest of the entries are the screens that
 * belong to a section of a chapter (editors, the alert history, the workspace settings).
 */
export const SCREEN_HELP: Readonly<Record<string, ScreenHelp>> = {
  ...Object.fromEntries(Object.entries(CHAPTER_APP_TARGETS).map(([slug, target]) => [
    target.route,
    { slug },
  ])),
  'ws.monitor.new': {
    slug: 'monitoring/monitors',
    anchor: { hu: 'uj-monitor-letrehozasa', en: 'creating-a-monitor' },
  },
  'ws.monitor.edit': {
    slug: 'monitoring/monitors',
    anchor: { hu: 'uj-monitor-letrehozasa', en: 'creating-a-monitor' },
  },
  // Tags have no chapter of their own; the monitor chapter explains them where a monitor gets its tags.
  'ws.tags': {
    slug: 'monitoring/monitors',
    anchor: { hu: 'uj-monitor-letrehozasa', en: 'creating-a-monitor' },
  },
  'ws.channel.new': {
    slug: 'monitoring/notifications',
    anchor: { hu: 'uj-csatorna-letrehozasa', en: 'creating-a-channel' },
  },
  'ws.channel': {
    slug: 'monitoring/notifications',
    anchor: { hu: 'uj-csatorna-letrehozasa', en: 'creating-a-channel' },
  },
  'ws.alerts': {
    slug: 'monitoring/notifications',
    anchor: { hu: 'riasztasi-naplo', en: 'alert-history' },
  },
  'ws.incident': {
    slug: 'monitoring/incidents',
    anchor: { hu: 'frissites-kozzetetele', en: 'posting-an-update' },
  },
  'ws.maintenance': {
    slug: 'monitoring/maintenances',
    anchor: { hu: 'uj-karbantartas-utemezese', en: 'scheduling-maintenance' },
  },
  'ws.status-page': {
    slug: 'monitoring/status-pages',
    anchor: { hu: 'az-elrendezes-osszeallitasa', en: 'building-the-layout' },
  },
  'ws.settings': {
    slug: 'workspace/workspace',
    anchor: { hu: 'a-munkaterulet-beallitasai', en: 'workspace-settings' },
  },
}

/** The help link of a screen. Throws for a route without one, so a typo fails every test of its view. */
export function screenHelp(route: string): ScreenHelp {
  const help = Object.hasOwn(SCREEN_HELP, route)
    ? SCREEN_HELP[route]
    : undefined
  if (!help)
    throw new Error(`No help chapter for the ${route} screen`)
  return help
}

/** The visible text of an HTML fragment (e.g. a release summary), without rendering or running it. */
export function plainText(html: string | null | undefined): string {
  if (!html)
    return ''
  // Block ends become spaces, so "<p>a</p><p>b</p>" reads "a b".
  const spaced = html.replace(/<\/(?:p|li|div|h[1-6])>|<br\s*\/?>/gi, '$& ')
  const doc = new DOMParser().parseFromString(spaced, 'text/html')
  for (const el of doc.body.querySelectorAll('script, style, template'))
    el.remove()
  return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim()
}

/** Letters that have no combining-mark decomposition, folded as the backend's Ascii::fold does. */
const EXTRA_FOLDS: Readonly<Record<string, string>> = {
  ø: 'o',
  Ø: 'o',
  ł: 'l',
  Ł: 'l',
  đ: 'd',
  Đ: 'd',
  ħ: 'h',
  Ħ: 'h',
  ı: 'i',
}

function foldChar(char: string): string {
  const base = EXTRA_FOLDS[char] ?? char.normalize('NFD').replace(/\p{Mn}/gu, '')
  return base.toLowerCase()
}

/** Accent-insensitive, lowercase form of a text (the search backend matches on the same form). */
export function fold(text: string): string {
  return Array.from(text, foldChar).join('')
}

/** The backend's default `min_term_length`: shorter query words are not searched, so not marked. */
const MIN_TERM_LENGTH = 3

export function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/**
 * Escapes a plain-text search snippet, then wraps the query words in `<mark>`. Words match
 * accent- and case-insensitively, so "ertesites" marks "Értesítés".
 */
export function highlight(snippet: string, query: string): string {
  const terms = [
    ...new Set(fold(query).split(/[^a-z0-9]+/).filter(t => t.length >= MIN_TERM_LENGTH)),
  ]

  // Folding can change a character's length, so every folded character remembers the
  // original character (by UTF-16 offset range) it came from.
  let folded = ''
  const starts: number[] = []
  const ends: number[] = []
  let offset = 0
  for (const char of Array.from(snippet)) {
    const f = foldChar(char)
    for (let i = 0; i < f.length; i++) {
      starts.push(offset)
      ends.push(offset + char.length)
    }
    folded += f
    offset += char.length
  }

  const ranges: Array<[number, number]> = []
  for (const term of terms) {
    let at = folded.indexOf(term)
    while (at !== -1) {
      ranges.push([starts[at] as number, ends[at + term.length - 1] as number])
      at = folded.indexOf(term, at + term.length)
    }
  }
  ranges.sort((a, b) => a[0] - b[0])
  const merged: Array<[number, number]> = []
  for (const [start, end] of ranges) {
    const last = merged.at(-1)
    if (last && start <= last[1])
      last[1] = Math.max(last[1], end)
    else
      merged.push([start, end])
  }

  let html = ''
  let cursor = 0
  for (const [start, end] of merged) {
    html += `${escapeHtml(snippet.slice(cursor, start))}<mark>${escapeHtml(snippet.slice(start, end))}</mark>`
    cursor = end
  }
  return html + escapeHtml(snippet.slice(cursor))
}

/** Whether the visitor asked the system to reduce motion. */
export function prefersReducedMotion(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}
