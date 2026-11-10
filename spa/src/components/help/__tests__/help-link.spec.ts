import { existsSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { applyLocale, LOCALES } from '../../../i18n'
import en from '../../../i18n/en.json'
import hu from '../../../i18n/hu.json'
import { createAppRouter } from '../../../router'
import { mountComponent } from '../../../test/mount'
import HelpLink from '../HelpLink.vue'
import { CHAPTER_APP_TARGETS, fold, SCREEN_HELP, screenHelp } from '../manual'

const MANUAL = resolve(process.cwd(), '../resources/manual')
const MESSAGES = { en, hu } as const

/** The manual's own heading anchors (ManualRenderer::anchor), from the Markdown source. */
const slugify = (text: string) => fold(text).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')

function markdown(locale: string, slug: string): string {
  return readFileSync(join(MANUAL, locale, `${slug}.md`), 'utf8')
}

const link = () => document.querySelector<HTMLAnchorElement>('[data-test="help-link"]')

afterEach(async () => {
  await applyLocale('en', false)
})

describe('help link', () => {
  it('links to the chapter with the chapter title in its label', async () => {
    mountComponent({
      render: () => h(HelpLink, { slug: 'workspace/api-tokens' }),
    })
    await flushPromises()

    expect(link()?.getAttribute('href')).toBe('/help/workspace/api-tokens')
    expect(link()?.getAttribute('aria-label')).toBe('Help: API tokens')
    // The tooltip repeats the label; describing the link by it would make screen readers say it twice.
    expect(link()?.hasAttribute('aria-describedby')).toBe(false)
  })

  it('opens the heading of the active language, and takes a label override', async () => {
    mountComponent({
      render: () => h(HelpLink, {
        ...screenHelp('ws.alerts'),
        label: 'Alerts',
      }),
    })
    await flushPromises()
    expect(link()?.getAttribute('href')).toBe('/help/monitoring/notifications#alert-history')
    expect(link()?.getAttribute('aria-label')).toBe('Help: Alerts')

    await applyLocale('hu', false)
    await flushPromises()
    expect(link()?.getAttribute('href')).toBe('/help/monitoring/notifications#riasztasi-naplo')
  })

  it('labels the link in Hungarian', async () => {
    await applyLocale('hu', false)
    mountComponent({
      render: () => h(HelpLink, { slug: 'account/account', anchor: 'profil' }),
    })
    await flushPromises()

    expect(link()?.getAttribute('href')).toBe('/help/account/account#profil')
    expect(link()?.getAttribute('aria-label')).toBe('Súgó: Saját fiók')
  })

  it('navigates to the help chapter', async () => {
    const { router } = mountComponent({
      render: () => h(HelpLink, { slug: 'monitoring/monitors' }),
    })
    await flushPromises()
    link()?.click()

    // The help view is loaded lazily; the navigation has to settle before the test ends.
    await expect.poll(() => router.currentRoute.value.name).toBe('help.chapter')
    expect(router.currentRoute.value.params.slug).toBe('monitoring/monitors')
  })
})

describe('screen help map', () => {
  it('links every screen a chapter opens back to that chapter', () => {
    for (const [slug, target] of Object.entries(CHAPTER_APP_TARGETS))
      expect(SCREEN_HELP[target.route]?.slug, target.route).toBe(slug)
  })

  it('names real routes and existing chapters, with a title in every language', () => {
    const router = createAppRouter(createMemoryHistory())
    for (const [route, help] of Object.entries(SCREEN_HELP)) {
      expect(router.hasRoute(route), route).toBe(true)
      expect(Object.hasOwn(CHAPTER_APP_TARGETS, help.slug), `${route} → ${help.slug}`).toBe(true)
      for (const locale of LOCALES) {
        expect(existsSync(join(MANUAL, locale, `${help.slug}.md`)), `${locale} ${help.slug}`).toBe(true)
        // The label repeats the chapter's own title.
        const h1 = /^# (.+)$/m.exec(markdown(locale, help.slug))?.[1]
        const titles = MESSAGES[locale].help.chapterTitles as Record<string, string>
        expect(titles[help.slug], `${locale} ${help.slug}`).toBe(h1)
      }
    }
  })

  it('points every anchor at a heading of the chapter in its own language', () => {
    for (const [route, help] of Object.entries(SCREEN_HELP)) {
      if (!help.anchor)
        continue
      for (const locale of LOCALES) {
        const anchors = [
          ...markdown(locale, help.slug).matchAll(/^#{2,3} (.+)$/gm),
        ].map(m => slugify(m[1] as string))
        expect(anchors, `${route} ${locale}`).toContain(help.anchor[locale])
      }
    }
  })

  it('has an entry for every screen a view asks about', () => {
    const sources = import.meta.glob<string>(['../../../views/**/*.vue'], {
      query: '?raw',
      import: 'default',
      eager: true,
    })
    const asked = new Set<string>()
    for (const source of Object.values(sources)) {
      for (const call of source.matchAll(/screenHelp\(([^)]*)\)/g)) {
        for (const name of (call[1] as string).matchAll(/'([^']+)'/g))
          asked.add(name[1] as string)
      }
    }

    expect(asked.size).toBeGreaterThanOrEqual(19)
    expect([...asked].filter(name => !Object.hasOwn(SCREEN_HELP, name))).toEqual([])
    expect(() => screenHelp('ws.nope')).toThrow()
  })
})
