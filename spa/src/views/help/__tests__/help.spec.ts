import { readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { highlight } from '../../../components/help/manual'
import { MANUAL_ICON_NAMES, manualIcon } from '../../../components/help/manualIcons'
import { i18n } from '../../../i18n'
import { me } from '../../../test/fixtures'
import { click, fill, mountApp } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'

// Switches the TOC to its mobile accordion and the motion preference, per test.
const flags = vi.hoisted(() => ({ mobile: false, reducedMotion: false }))

vi.mock('vuetify', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vuetify')>()
  const { computed } = await import('vue')
  return {
    ...actual,
    useDisplay: (...args: Parameters<typeof actual.useDisplay>) => {
      const display = actual.useDisplay(...args)
      return {
        ...display,
        smAndDown: computed(() => flags.mobile || display.smAndDown.value),
      }
    },
  }
})

vi.mock('../../../components/help/manual', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../components/help/manual')>()
  return {
    ...actual,
    prefersReducedMotion: () => flags.reducedMotion,
  }
})

afterEach(() => {
  flags.mobile = false
  flags.reducedMotion = false
})

const text = () => document.body.textContent ?? ''

function base(user = me()) {
  return {
    'GET /setup': json({ required: false }),
    'GET /me': json({ data: user }),
  }
}

function index(over: Record<string, unknown> = {}) {
  return {
    data: [
      {
        id: 'start',
        icon: 'mdi-information-outline',
        title: 'Getting started',
        chapters: [
          {
            slug: 'start/getting-started',
            number: '1.1',
            title: 'First steps',
            summary: 'Sign in and look around.',
          },
        ],
      },
      {
        id: 'monitoring',
        icon: 'mdi-monitor-dashboard',
        title: 'Monitoring',
        chapters: [
          {
            slug: 'monitoring/monitors',
            number: '2.1',
            title: 'Monitors',
            summary: 'What a monitor checks.',
          },
          {
            slug: 'monitoring/notifications',
            number: '2.2',
            title: 'Notifications',
            summary: 'Who hears about it.',
          },
        ],
      },
    ],
    locale: 'en',
    fallback: false,
    ...over,
  }
}

const HTML = [
  '<h1>Monitors</h1>\n',
  '<p>Press <i class="manual-icon" data-icon="mdi-plus" aria-hidden="true"></i> to add one.',
  ' Unknown <i class="manual-icon" data-icon="mdi-no-such-icon" aria-hidden="true"></i> stays empty.</p>',
  '<h2 id="first-steps">First steps</h2>',
  '<p>See <a class="manual-link" data-slug="monitoring/notifications" tabindex="0" role="link">notifications</a>,',
  ' open <a class="manual-route" data-route="ws.monitors" tabindex="0" role="link">the monitor list</a>,',
  ' read <a class="manual-route" data-route="changelog" tabindex="0" role="link">the changelog</a>,',
  ' not <a class="manual-route" data-route="ws.nope" tabindex="0" role="link">a ghost</a>,',
  ' not <a class="manual-route" data-route="ws.monitor" tabindex="0" role="link">one monitor</a>,',
  ' or <a class="manual-external" href="https://example.com" target="_blank" rel="noopener noreferrer">the site</a>.</p>',
  '<h3 id="details">Details</h3>',
  '<div class="manual-callout" data-type="tip"><p>A tip.</p></div>',
  '<div class="manual-table-wrap"><table><tr><td>cell</td></tr></table></div>',
].join('')

function chapter(over: Record<string, unknown> = {}) {
  return {
    data: {
      slug: 'monitoring/monitors',
      number: '2.1',
      title: 'Monitors',
      summary: 'What a monitor checks.',
      section: 'monitoring',
      position: 1,
      total: 2,
      readingMinutes: 3,
      html: HTML,
      headings: [
        { anchor: 'first-steps', text: 'First steps', level: 2 },
        { anchor: 'details', text: 'Details', level: 3 },
      ],
      prev: { slug: 'start/getting-started', title: 'First steps' },
      next: { slug: 'monitoring/notifications', title: 'Notifications' },
      locale: 'en',
      fallback: false,
      ...over,
    },
  }
}

async function loadHungarian(): Promise<void> {
  i18n.global.setLocaleMessage('hu', (await import('../../../i18n/hu.json')).default)
}

describe('help index', () => {
  afterEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('lists the chapters of every section with their number and summary', async () => {
    fakeApi({
      ...base(),
      'GET /manual': json(index()),
    })
    await mountApp('/help')
    await expect.poll(() => document.querySelectorAll('[data-test="section"]').length).toBe(2)
    const sections = document.querySelectorAll('[data-test="section"]')
    expect(sections[0]?.textContent).toContain('Getting started')
    expect(sections[1]?.querySelectorAll('[data-test="chapter"]')).toHaveLength(2)
    expect(sections[1]?.querySelector('[data-test="chapter-number"]')?.textContent).toBe('2.1')
    expect(sections[1]?.textContent).toContain('What a monitor checks.')
    expect(sections[1]?.querySelector('a[data-test="chapter"]')?.getAttribute('href')).toBe('/help/monitoring/monitors')
    expect(document.querySelector('[data-test="fallback"]')).toBeNull()
  })

  it('searches 300 ms after typing stops and marks the words in the escaped snippet', async () => {
    const queries: string[] = []
    const server = fakeApi({
      ...base(),
      'GET /manual': json(index()),
      'GET /manual/search': (req) => {
        queries.push(new URL(req.url).searchParams.get('q') ?? '')
        return json({
          data: [
            {
              slug: 'monitoring/notifications',
              section: 'monitoring',
              title: 'Notifications',
              snippet: 'Az Értesítés <b>csatorna</b> küld.',
              score: 3,
            },
          ],
          locale: 'en',
          fallback: false,
        })
      },
    })
    await mountApp('/help')
    await flushPromises()
    fill('[data-test="manual-search"] input', 'erte')
    fill('[data-test="manual-search"] input', 'ertesites')
    await new Promise(r => setTimeout(r, 150))
    expect(server.of('GET /manual/search')).toHaveLength(0)
    await expect.poll(() => document.querySelector('[data-test="search-hit"]')).not.toBeNull()
    expect(queries).toEqual(['ertesites'])
    const snippet = document.querySelector('[data-test="search-hit"] .hit-snippet')
    expect(snippet?.querySelector('mark')?.textContent).toBe('Értesítés')
    expect(snippet?.querySelector('b')).toBeNull()
    expect(snippet?.textContent).toContain('<b>csatorna</b>')
    expect(document.querySelector('[data-test="search-hit"]')?.getAttribute('href')).toBe('/help/monitoring/notifications')
  })

  it('says so when nothing matches', async () => {
    fakeApi({
      ...base(),
      'GET /manual': json(index()),
      'GET /manual/search': json({ data: [], locale: 'en', fallback: false }),
    })
    await mountApp('/help')
    await flushPromises()
    fill('[data-test="manual-search"] input', 'zzz')
    await expect.poll(() => document.querySelector('[data-test="search-empty"]')).not.toBeNull()
    expect(text()).toContain('No chapter matches your search.')
  })

  it('shows a failure and retries it', async () => {
    const server = fakeApi({
      ...base(),
      'GET /manual': [
        problem(500, { title: 'Boom' }),
        json(index()),
      ],
    })
    await mountApp('/help')
    await expect.poll(() => document.querySelector('.problem-alert')?.textContent).toContain('Boom')
    await click('[data-test="retry"]')
    await expect.poll(() => document.querySelectorAll('[data-test="section"]').length).toBe(2)
    expect(document.querySelector('[data-test="retry"]')).toBeNull()
    expect(server.of('GET /manual')).toHaveLength(2)
  })

  it('lets a locale switch during the first load win', async () => {
    await loadHungarian()
    let release: (() => void) | undefined
    fakeApi({
      ...base(),
      'GET /manual': [
        // The first (English) answer arrives only after the Hungarian one.
        () => new Promise<Response>((r) => {
          release = () => r(json(index()))
        }),
        json(index({
          data: [
            {
              id: 'start',
              icon: 'mdi-information-outline',
              title: 'Első lépések',
              chapters: [],
            },
          ],
          locale: 'hu',
        })),
      ],
    })
    await mountApp('/help')
    await expect.poll(() => release).toBeTypeOf('function')
    i18n.global.locale.value = 'hu'
    await expect.poll(() => text()).toContain('Első lépések')
    release?.()
    await flushPromises()
    expect(text()).toContain('Első lépések')
    expect(text()).not.toContain('Getting started')
  })

  it('announces the search outcome in a live region', async () => {
    fakeApi({
      ...base(),
      'GET /manual': json(index()),
      'GET /manual/search': json({
        data: [
          {
            slug: 'monitoring/monitors',
            section: 'monitoring',
            title: 'Monitors',
            snippet: 'A monitor checks.',
            score: 1,
          },
          {
            slug: 'monitoring/notifications',
            section: 'monitoring',
            title: 'Notifications',
            snippet: 'A monitor alerts.',
            score: 1,
          },
        ],
        locale: 'en',
        fallback: false,
      }),
    })
    await mountApp('/help')
    await flushPromises()
    const status = document.querySelector('[data-test="search-status"]')
    expect(status?.getAttribute('role')).toBe('status')
    expect(status?.getAttribute('aria-live')).toBe('polite')
    fill('[data-test="manual-search"] input', 'monitor')
    await expect.poll(() => document.querySelector('[data-test="search-status"]')?.textContent).toContain('2 matching chapters')
  })

  it('refetches the tree when the locale changes', async () => {
    await loadHungarian()
    const server = fakeApi({
      ...base(),
      'GET /manual': [
        json(index()),
        json(index({
          data: [
            {
              id: 'start',
              icon: 'mdi-information-outline',
              title: 'Első lépések',
              chapters: [],
            },
          ],
          locale: 'hu',
        })),
      ],
    })
    await mountApp('/help')
    await expect.poll(() => text()).toContain('Getting started')
    i18n.global.locale.value = 'hu'
    await expect.poll(() => text()).toContain('Első lépések')
    expect(text()).not.toContain('Getting started')
    expect(server.of('GET /manual')).toHaveLength(2)
  })
})

describe('help chapter', () => {
  afterEach(() => {
    i18n.global.locale.value = 'en'
  })

  async function openChapter(user = me(), reply = json(chapter())) {
    const slugs: string[] = []
    const server = fakeApi({
      ...base(user),
      'GET /manual/chapter': (req) => {
        slugs.push(new URL(req.url).searchParams.get('slug') ?? '')
        return reply.clone()
      },
    })
    const app = await mountApp('/help/monitoring/monitors')
    await expect.poll(() => document.querySelector('[data-test="content"]')).not.toBeNull()
    await flushPromises()
    return { ...app, server, slugs }
  }

  it('renders the html, a contents list from the headings and previous/next links', async () => {
    const { slugs, router } = await openChapter()
    expect(router.currentRoute.value.name).toBe('help.chapter')
    expect(slugs).toEqual(['monitoring/monitors'])
    expect(document.querySelector('h1')?.textContent).toContain('Monitors')
    const content = document.querySelector('[data-test="content"]')
    // The page title is the view's own <h1>; the chapter's Markdown title is not repeated.
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(content?.querySelector('h1')).toBeNull()
    expect(content?.querySelector('h2#first-steps')?.textContent).toBe('First steps')
    expect(content?.querySelector('.manual-callout[data-type="tip"]')).not.toBeNull()
    expect(content?.querySelector('.manual-table-wrap > table')).not.toBeNull()
    const toc = [
      ...document.querySelectorAll('[data-test="toc"] a'),
    ].map(a => [
      a.textContent,
      a.getAttribute('href'),
    ])
    expect(toc).toEqual([
      ['First steps', '#first-steps'],
      ['Details', '#details'],
    ])
    expect(document.querySelector('[data-test="prev"]')?.getAttribute('href')).toBe('/help/start/getting-started')
    expect(document.querySelector('[data-test="next"]')?.getAttribute('href')).toBe('/help/monitoring/notifications')
    expect(document.querySelector('[data-test="next"]')?.textContent).toContain('Notifications')
    expect(document.querySelector('[data-test="fallback"]')).toBeNull()
  })

  it('replaces known icons with their svg and leaves unknown ones empty', async () => {
    await openChapter()
    const icons = document.querySelectorAll('[data-test="content"] i.manual-icon')
    expect(icons[0]?.querySelector('svg path')?.getAttribute('d')).toBe(manualIcon('mdi-plus'))
    expect(icons[1]?.childNodes).toHaveLength(0)
  })

  it('opens a cross-referenced chapter on click and on Enter', async () => {
    const { router, slugs } = await openChapter()
    const link = () => document.querySelector<HTMLElement>('[data-test="content"] a.manual-link')
    expect(link()?.getAttribute('href')).toBe('/help/monitoring/notifications')
    link()?.click()
    await expect.poll(() => router.currentRoute.value.params.slug).toBe('monitoring/notifications')
    expect(router.currentRoute.value.name).toBe('help.chapter')
    await expect.poll(() => slugs).toEqual(['monitoring/monitors', 'monitoring/notifications'])

    await router.push('/help/monitoring/monitors')
    await flushPromises()
    await expect.poll(() => link()).not.toBeNull()
    link()?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
    await expect.poll(() => router.currentRoute.value.params.slug).toBe('monitoring/notifications')
  })

  it('opens app routes in the current workspace and turns unusable ones into text', async () => {
    const { router } = await openChapter()
    const routeLinks = [
      ...document.querySelectorAll<HTMLAnchorElement>('[data-test="content"] a.manual-route'),
    ]
    expect(routeLinks.map(a => a.dataset.route)).toEqual(['ws.monitors', 'changelog'])
    expect(routeLinks[0]?.getAttribute('href')).toBe('/w/acme/monitors')
    const plain = [
      ...document.querySelectorAll('[data-test="content"] .manual-route-text'),
    ].map(s => s.textContent)
    expect(plain).toEqual(['a ghost', 'one monitor'])

    routeLinks[0]?.click()
    await expect.poll(() => router.currentRoute.value.name).toBe('ws.monitors')
    expect(router.currentRoute.value.params.workspace).toBe('acme')
  })

  it('opens a route that needs no workspace directly, even without workspaces', async () => {
    const { router } = await openChapter(me({ workspaces: [] }))
    const routeLinks = [
      ...document.querySelectorAll<HTMLAnchorElement>('[data-test="content"] a.manual-route'),
    ]
    expect(routeLinks.map(a => a.dataset.route)).toEqual(['changelog'])
    expect(document.querySelector('[data-test="content"]')?.textContent).toContain('the monitor list')
    routeLinks[0]?.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }))
    await expect.poll(() => router.currentRoute.value.name).toBe('changelog')
  })

  it('leaves external links to the browser', async () => {
    await openChapter()
    const external = document.querySelector<HTMLAnchorElement>('[data-test="content"] a.manual-external')
    const event = new MouseEvent('click', { bubbles: true, cancelable: true })
    external?.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(false)
    expect(external?.target).toBe('_blank')
  })

  it('shows the fallback notice for Hungarian content in another UI language', async () => {
    await openChapter(me(), json(chapter({ locale: 'hu', fallback: true })))
    expect(document.querySelector('[data-test="fallback"]')?.textContent).toContain('This chapter is only available in Hungarian for now.')
  })

  it('shows no fallback notice when the UI is in the served language', async () => {
    await loadHungarian()
    i18n.global.locale.value = 'hu'
    await openChapter(me(), json(chapter({ locale: 'hu', fallback: true })))
    expect(document.querySelector('[data-test="fallback"]')).toBeNull()
  })

  it('opens a new chapter at the top with focus on its title', async () => {
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    const { router } = await openChapter()
    const ours = () => scrollTo.mock.calls.filter(([arg]) => typeof arg === 'object' && arg !== null && 'top' in arg)
    // The first chapter opens wherever the page is; only a chapter change scrolls.
    expect(ours()).toHaveLength(0)
    await click('[data-test="next"]')
    await expect.poll(() => router.currentRoute.value.params.slug).toBe('monitoring/notifications')
    await expect.poll(() => ours()).toEqual([
      [
        { top: 0, behavior: 'smooth' },
      ],
    ])
    const title = document.querySelector('[data-test="title"]')
    expect(title?.getAttribute('tabindex')).toBe('-1')
    expect(document.activeElement).toBe(title)

    flags.reducedMotion = true
    await router.push('/help/monitoring/monitors')
    await expect.poll(() => ours()).toHaveLength(2)
    expect(ours()[1]).toEqual([
      { top: 0, behavior: 'auto' },
    ])
  })

  it('scrolls to a heading from the contents and focuses it', async () => {
    const scrollIntoView = vi.fn()
    Element.prototype.scrollIntoView = scrollIntoView
    try {
      await openChapter()
      await click('[data-test="toc"] a[href="#details"]')
      const target = document.getElementById('details')
      expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' })
      expect(target?.getAttribute('tabindex')).toBe('-1')
      expect(document.activeElement).toBe(target)

      flags.reducedMotion = true
      await click('[data-test="toc"] a[href="#first-steps"]')
      expect(scrollIntoView).toHaveBeenLastCalledWith({ behavior: 'auto', block: 'start' })
    }
    finally {
      // jsdom has no scrollIntoView of its own.
      delete (Element.prototype as Partial<Element>).scrollIntoView
    }
  })

  it('collapses the contents into a panel above the chapter on small screens', async () => {
    flags.mobile = true
    await openChapter()
    const toc = document.querySelector('[data-test="toc"]')
    expect(toc?.querySelector('.v-expansion-panel')).not.toBeNull()
    expect(document.querySelector('aside')).toBeNull()
    // The panel starts collapsed and sits before the chapter text.
    expect(toc?.querySelector('a[href="#details"]')).toBeNull()
    expect(toc!.compareDocumentPosition(document.querySelector('[data-test="content"]')!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    await click('[data-test="toc"] .v-expansion-panel-title')
    await expect.poll(() => toc?.querySelector('a[href="#details"]')).not.toBeNull()
  })

  it('shows a forbidden error as a problem with retry, not as a missing chapter', async () => {
    fakeApi({
      ...base(),
      'GET /manual/chapter': problem(403, { title: 'Forbidden' }),
    })
    await mountApp('/help/monitoring/monitors')
    await expect.poll(() => document.querySelector('.problem-alert')?.textContent).toContain('Forbidden')
    expect(document.querySelector('[data-test="not-found"]')).toBeNull()
    expect(document.querySelector('[data-test="retry"]')).not.toBeNull()
  })

  it('shows a progress bar while the first load is pending', async () => {
    let release: (() => void) | undefined
    fakeApi({
      ...base(),
      'GET /manual/chapter': () => new Promise<Response>((r) => {
        release = () => r(json(chapter()))
      }),
    })
    await mountApp('/help/monitoring/monitors')
    await expect.poll(() => document.querySelector('[data-test="loading"]')).not.toBeNull()
    release?.()
    await expect.poll(() => document.querySelector('[data-test="content"]')).not.toBeNull()
    expect(document.querySelector('[data-test="loading"]')).toBeNull()
  })

  it('shows a not-found state with a way back for an unknown chapter', async () => {
    fakeApi({
      ...base(),
      'GET /manual/chapter': problem(404, { title: 'Not Found' }),
    })
    await mountApp('/help/nope/nothing')
    await expect.poll(() => document.querySelector('[data-test="not-found"]')).not.toBeNull()
    expect(text()).toContain('There is no such chapter.')
    expect(document.querySelector('[data-test="back"]')?.getAttribute('href')).toBe('/help')
    expect(document.querySelector('[data-test="retry"]')).toBeNull()
  })

  it('retries a failed load', async () => {
    const server = fakeApi({
      ...base(),
      'GET /manual/chapter': [
        problem(500, { title: 'Boom' }),
        json(chapter()),
      ],
    })
    await mountApp('/help/monitoring/monitors')
    await expect.poll(() => document.querySelector('.problem-alert')?.textContent).toContain('Boom')
    await click('[data-test="retry"]')
    await expect.poll(() => document.querySelector('[data-test="content"]')).not.toBeNull()
    expect(server.of('GET /manual/chapter')).toHaveLength(2)
  })

  it('reloads the chapter when the locale changes', async () => {
    await loadHungarian()
    const server = fakeApi({
      ...base(),
      'GET /manual/chapter': [
        json(chapter()),
        json(chapter({ title: 'Monitorok', locale: 'hu' })),
      ],
    })
    await mountApp('/help/monitoring/monitors')
    await expect.poll(() => document.querySelector('h1')?.textContent).toContain('Monitors')
    i18n.global.locale.value = 'hu'
    await expect.poll(() => document.querySelector('h1')?.textContent).toContain('Monitorok')
    expect(server.of('GET /manual/chapter')).toHaveLength(2)
  })
})

describe('manual helpers', () => {
  it('marks query words accent- and case-insensitively in escaped text', () => {
    expect(highlight('Az Értesítés <b>beállítása</b>', 'ertesites')).toBe('Az <mark>Értesítés</mark> &lt;b&gt;beállítása&lt;/b&gt;')
    expect(highlight('értesítések és csatornák', 'ÉRTESÍTÉS csatorna')).toBe('<mark>értesítés</mark>ek és <mark>csatorná</mark>k')
    // Words below the backend's minimum term length are not searched, so not marked.
    expect(highlight('az ok', 'az')).toBe('az ok')
    expect(highlight('a & b', '')).toBe('a &amp; b')
  })

  it('maps every icon the manual content and _meta.php name', () => {
    const root = resolve(process.cwd(), '../resources/manual')
    const files = (dir: string): string[] => readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory()
      ? files(join(dir, entry.name))
      : entry.name.endsWith('.md')
        ? [
            join(dir, entry.name),
          ]
        : [])
    const used = new Set<string>()
    for (const file of files(root)) {
      for (const match of readFileSync(file, 'utf8').matchAll(/\[\[icon:([^\]|\s]+)/g))
        used.add(match[1] as string)
    }
    for (const match of readFileSync(join(root, '_meta.php'), 'utf8').matchAll(/'icon'\s*=>\s*'([^']+)'/g))
      used.add(match[1] as string)

    expect(used.size).toBeGreaterThan(0)
    expect([...used].filter(name => manualIcon(name) === null)).toEqual([])
    expect(MANUAL_ICON_NAMES.every(name => manualIcon(name) !== null)).toBe(true)
    expect(manualIcon('mdi-no-such-icon')).toBeNull()
    expect(manualIcon('constructor')).toBeNull()
  })
})
