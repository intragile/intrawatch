import type { StatusPage, StatusPageCreateBody, StatusPageLayoutBody, StatusPageUpdateBody } from '../../api/types'
import { deepEqual } from '../monitors/forms/model'

export const LOCALES = ['en', 'hu'] as const
export const UPTIME_WINDOWS = [1, 7, 30, 90, 365] as const

/** The editable settings of a status page; empty strings stand for `null` on the wire. */
export interface PageForm {
  slug: string
  title: string
  description: string
  locale: 'en' | 'hu'
  published: boolean
  custom_domain: string
  accent_color: string
  logo_url: string
  favicon_url: string
  footer: string
  custom_css: string
  bar_days: number
  uptime_days: number
  upcoming_maintenance_days: number
  incident_history_days: number
  show_latency: boolean
}

export function newPageForm(): PageForm {
  return {
    slug: '',
    title: '',
    description: '',
    locale: 'en',
    published: false,
    custom_domain: '',
    accent_color: '',
    logo_url: '',
    favicon_url: '',
    footer: '',
    custom_css: '',
    bar_days: 90,
    uptime_days: 30,
    upcoming_maintenance_days: 14,
    incident_history_days: 7,
    show_latency: true,
  }
}

export function formFromPage(page: StatusPage): PageForm {
  return {
    slug: page.slug,
    title: page.title,
    description: page.description ?? '',
    locale: page.locale === 'hu'
      ? 'hu'
      : 'en',
    published: page.published,
    custom_domain: page.custom_domain ?? '',
    accent_color: page.accent_color ?? '',
    logo_url: page.logo_url ?? '',
    favicon_url: page.favicon_url ?? '',
    footer: page.footer ?? '',
    custom_css: page.custom_css ?? '',
    bar_days: page.bar_days,
    uptime_days: page.uptime_days,
    upcoming_maintenance_days: page.upcoming_maintenance_days,
    incident_history_days: page.incident_history_days,
    show_latency: page.show_latency,
  }
}

function orNull(value: string): string | null {
  return value.trim() === ''
    ? null
    : value
}

/** The wire value of every setting except the custom domain, which needs its own permission. */
function settings(form: PageForm): Omit<StatusPageCreateBody, 'custom_domain'> {
  return {
    slug: form.slug.trim(),
    title: form.title,
    description: orNull(form.description),
    locale: form.locale,
    published: form.published,
    accent_color: orNull(form.accent_color.trim()),
    logo_url: orNull(form.logo_url.trim()),
    favicon_url: orNull(form.favicon_url.trim()),
    footer: orNull(form.footer),
    custom_css: orNull(form.custom_css),
    bar_days: Number(form.bar_days),
    uptime_days: form.uptime_days as StatusPageCreateBody['uptime_days'],
    upcoming_maintenance_days: Number(form.upcoming_maintenance_days),
    incident_history_days: Number(form.incident_history_days),
    show_latency: form.show_latency,
  }
}

export function createBody(form: PageForm, withDomain: boolean): StatusPageCreateBody {
  const body: StatusPageCreateBody = settings(form)
  if (withDomain && form.custom_domain.trim() !== '')
    body.custom_domain = form.custom_domain.trim()
  return body
}

/** Only the settings that differ from the loaded ones; an unchanged domain is never resent. */
export function patchBody(initial: PageForm, current: PageForm, withDomain: boolean): StatusPageUpdateBody {
  const before = settings(initial)
  const after = settings(current)
  const body: Record<string, unknown> = {}
  for (const key of Object.keys(after) as Array<keyof typeof after>) {
    if (!deepEqual(before[key], after[key]))
      body[key] = after[key]
  }
  if (withDomain && initial.custom_domain.trim() !== current.custom_domain.trim())
    body.custom_domain = orNull(current.custom_domain.trim())
  return body as StatusPageUpdateBody
}

/** Error roots of the settings controls, so every other key falls back to the alert. */
export const PAGE_FIELDS = [
  'slug',
  'title',
  'description',
  'locale',
  'published',
  'custom_domain',
  'accent_color',
  'logo_url',
  'favicon_url',
  'footer',
  'custom_css',
  'bar_days',
  'uptime_days',
  'upcoming_maintenance_days',
  'incident_history_days',
  'show_latency',
]

export interface LayoutItem {
  /** Client-side identity for list rendering; not sent. */
  key: number
  monitor_id: number
  display_name: string
  description: string
  expand_children: boolean
  show_uptime: boolean
}

export interface LayoutSection {
  key: number
  name: string
  description: string
  items: LayoutItem[]
}

let nextKey = 1
export const newKey = (): number => nextKey++

export function newSection(): LayoutSection {
  return {
    key: newKey(),
    name: '',
    description: '',
    items: [],
  }
}

export function newItem(monitorId: number): LayoutItem {
  return {
    key: newKey(),
    monitor_id: monitorId,
    display_name: '',
    description: '',
    expand_children: false,
    show_uptime: true,
  }
}

/** The editable layout of a loaded page. */
export function layoutFromPage(page: StatusPage): LayoutSection[] {
  return (page.sections ?? []).map(section => ({
    key: newKey(),
    name: section.name,
    description: section.description ?? '',
    items: (section.items ?? []).map(i => ({
      key: newKey(),
      monitor_id: i.monitor_id,
      display_name: i.display_name ?? '',
      description: i.description ?? '',
      expand_children: i.expand_children ?? false,
      show_uptime: i.show_uptime ?? true,
    })),
  }))
}

/** The full replacement body; array order is the display order. */
export function layoutBody(sections: LayoutSection[]): StatusPageLayoutBody {
  return {
    sections: sections.map(s => ({
      name: s.name,
      description: orNull(s.description),
      items: s.items.map(i => ({
        monitor_id: i.monitor_id,
        display_name: orNull(i.display_name),
        description: orNull(i.description),
        expand_children: i.expand_children,
        show_uptime: i.show_uptime,
      })),
    })),
  }
}

/** A copy of the array with the element at `from` moved to `to`. */
export function moved<T>(list: T[], from: number, to: number): T[] {
  if (to < 0 || to >= list.length || from === to)
    return list
  const copy = [...list]
  const [entry] = copy.splice(from, 1)
  if (entry !== undefined)
    copy.splice(to, 0, entry)
  return copy
}
