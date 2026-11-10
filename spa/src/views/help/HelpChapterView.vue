<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import type { ApiError } from '../../api'
import type { components, paths } from '../../api/schema'
import {
  mdiAlertOutline,
  mdiFormatListNumbered,
  mdiInformationOutline,
  mdiLightbulbOnOutline,
  mdiMagnify,
} from '@mdi/js'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { api } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { chapterAppTarget, chapterLocation, plainText, prefersReducedMotion } from '../../components/help/manual'
import { manualIcon } from '../../components/help/manualIcons'
import ManualSidebar from '../../components/help/ManualSidebar.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useSession } from '../../stores/session'

type Chapter = components['schemas']['ChapterResource']
type Tree = paths['/manual']['get']['responses'][200]['content']['application/json']['data']

const props = defineProps<{
  slug: string
}>()
/** The callout types the content uses, with their icon; the first three also get a spoken/visible label. */
const CALLOUT_ICONS: Readonly<Record<string, string>> = {
  tip: mdiLightbulbOnOutline,
  warning: mdiAlertOutline,
  note: mdiInformationOutline,
  steps: mdiFormatListNumbered,
}
const LABELLED_CALLOUTS = new Set(['tip', 'warning', 'note'])

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const can = useCan()
const failure = useApiFailure()
// Below md the contents move above the chapter (collapsed) instead of beside it.
const { smAndDown: mobile } = useDisplay()

const chapter = ref<Chapter | null>(null)
const loading = ref(false)
const error = ref<ApiError | null>(null)
const content = ref<HTMLElement | null>(null)
const heading = ref<HTMLElement | null>(null)
/** Bumped on every loaded chapter, so the rendered markup is re-created and enhanced again. */
const loads = ref(0)

const notFound = computed(() => error.value?.status === 404)

let sequence = 0
// A move to another chapter still owes a scroll to the top until some load finishes: a language switch
// or a retry that supersedes the load the move started must not drop it.
let topPending = false

/** Starts the reader at the top of a newly opened chapter, with focus on its title. */
async function showTop(): Promise<void> {
  await nextTick()
  window.scrollTo({
    top: 0,
    behavior: prefersReducedMotion()
      ? 'auto'
      : 'smooth',
  })
  heading.value?.focus({ preventScroll: true })
}

/** Scrolls to the heading named by the URL hash (a deep link), if the chapter has it. */
async function showHash(): Promise<boolean> {
  const anchor = decodeURIComponent(route.hash.replace(/^#/, ''))
  if (anchor === '')
    return false
  await nextTick()
  const target = document.getElementById(anchor)
  if (!target || !content.value?.contains(target))
    return false
  target.scrollIntoView?.({
    behavior: prefersReducedMotion()
      ? 'auto'
      : 'smooth',
    block: 'start',
  })
  return true
}

async function load(moved = false): Promise<void> {
  const mine = ++sequence
  if (moved)
    topPending = true
  loading.value = true
  error.value = null
  try {
    const { data } = await api.GET('/manual/chapter', {
      params: {
        query: { slug: props.slug },
      },
    })
    if (mine === sequence && data) {
      chapter.value = data.data
      loads.value++
      const top = topPending
      topPending = false
      void showHash().then((shown) => {
        if (!shown && top)
          void showTop()
      })
    }
  }
  catch (e) {
    if (mine === sequence) {
      chapter.value = null
      error.value = failure(e)
      // The error replaces the chapter at the top of the reader, so show it there.
      if (topPending) {
        topPending = false
        void showTop()
      }
    }
  }
  finally {
    if (mine === sequence)
      loading.value = false
  }
}

// A new slug (previous/next, a cross-reference) and a language switch both load the chapter again.
watch([
  () => props.slug,
  locale,
], ([slug], previous) => {
  if (slug === '') {
    void router.replace({ name: 'help' })
    return
  }
  // The router keeps the scroll offset, so a different chapter would open where the last one ended.
  // (On the immediate run Vue passes an empty array, so the first chapter does not count as a move.)
  const before = previous?.[0]
  const moved = before !== undefined && before !== slug
  void load(moved)
}, { immediate: true })

// A link to another heading of the open chapter only changes the hash.
watch(() => route.hash, () => {
  if (chapter.value)
    void showHash()
})

/** The manual tree, for the section title and the neighbours' numbers. Optional: a failure only hides those. */
const tree = ref<Tree | null>(null)
let treeSequence = 0

async function loadTree(): Promise<void> {
  const mine = ++treeSequence
  try {
    const { data } = await api.GET('/manual')
    if (mine === treeSequence)
      tree.value = data?.data ?? null
  }
  catch {
    if (mine === treeSequence)
      tree.value = null
  }
}

watch(locale, () => void loadTree(), { immediate: true })

const sectionTitle = computed(() => tree.value?.find(s => s.id === chapter.value?.section)?.title ?? null)

function chapterNumber(slug: string | undefined): string | null {
  if (!slug || !tree.value)
    return null
  for (const section of tree.value) {
    const found = section.chapters.find(c => c.slug === slug)
    if (found)
      return found.number
  }
  return null
}

const prevNumber = computed(() => chapterNumber(chapter.value?.prev?.slug))
const nextNumber = computed(() => chapterNumber(chapter.value?.next?.slug))

/** The compact search hands the query over to the index, which searches it at once. */
const searchQuery = ref('')

function searchManual(): void {
  const q = searchQuery.value.trim()
  void router.push(q === ''
    ? { name: 'help' }
    : {
        name: 'help',
        query: { q },
      })
}

const showFallback = computed(() => chapter.value !== null && chapter.value.fallback && chapter.value.locale !== locale.value)

/** The workspace `[[route:…]]` links open in: the one in view, else the last used (or first) one. */
const workspaceSlug = computed(() => session.current?.slug ?? session.homeWorkspace())

/** The location of an app route named in the content, or null when it cannot be opened from here. */
function appLocation(name: string): RouteLocationRaw | null {
  if (!router.hasRoute(name))
    return null
  const record = router.getRoutes().find(r => r.name === name)
  if (!record)
    return null
  const params = [
    ...record.path.matchAll(/:(\w+)/g),
  ].map(m => m[1])
  // Only the workspace can be filled in; a route that needs a record id is not linkable.
  if (params.some(p => p !== 'workspace'))
    return null
  if (params.length === 0)
    return { name }
  const workspace = workspaceSlug.value
  return workspace === null
    ? null
    : {
        name,
        params: { workspace },
      }
}

/** "Open in the app": the screen the chapter describes, when it exists and the user may open it here. */
const appTarget = computed<RouteLocationRaw | null>(() => {
  const target = chapter.value
    ? chapterAppTarget(chapter.value.slug)
    : null
  if (!target || !router.hasRoute(target.route))
    return null
  if (!target.route.startsWith('ws.'))
    return { name: target.route }
  const workspace = session.current?.slug
  if (!workspace || (target.ability && !can(target.ability)))
    return null
  return {
    name: target.route,
    params: { workspace },
  }
})

function linkTarget(link: HTMLElement): RouteLocationRaw | null {
  if (link.classList.contains('manual-link')) {
    const slug = link.dataset.slug
    return slug
      ? chapterLocation(slug)
      : null
  }
  if (link.classList.contains('manual-route')) {
    const name = link.dataset.route
    return name
      ? appLocation(name)
      : null
  }
  return null
}

/**
 * Turns the rendered markup into working UI: icons get their SVG, internal links get a real
 * href (so they can be opened in a new tab), and route links that cannot be opened become text.
 */
function enhance(root: HTMLElement): void {
  for (const icon of root.querySelectorAll<HTMLElement>('i.manual-icon[data-icon]')) {
    const path = manualIcon(icon.dataset.icon)
    icon.replaceChildren()
    if (path === null)
      continue
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
    svg.setAttribute('viewBox', '0 0 24 24')
    svg.setAttribute('aria-hidden', 'true')
    const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path')
    shape.setAttribute('d', path)
    svg.append(shape)
    icon.append(svg)
  }
  for (const callout of root.querySelectorAll<HTMLElement>('.manual-callout[data-type]')) {
    if (callout.dataset.enhanced !== undefined)
      continue
    callout.dataset.enhanced = ''
    const type = callout.dataset.type ?? ''
    const body = document.createElement('div')
    body.className = 'manual-callout-body'
    body.append(...callout.childNodes)
    if (LABELLED_CALLOUTS.has(type)) {
      const label = document.createElement('strong')
      label.className = 'manual-callout-label'
      label.textContent = `${t(`help.callout.${type}`)} `
      const first = body.firstElementChild
      if (first?.tagName === 'P')
        first.prepend(label)
      else
        body.prepend(label)
    }
    const path = Object.hasOwn(CALLOUT_ICONS, type)
      ? CALLOUT_ICONS[type]
      : undefined
    if (path) {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
      svg.setAttribute('viewBox', '0 0 24 24')
      svg.setAttribute('aria-hidden', 'true')
      svg.setAttribute('class', 'manual-callout-icon')
      const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path')
      shape.setAttribute('d', path)
      svg.append(shape)
      callout.append(svg)
    }
    callout.append(body)
  }
  for (const link of root.querySelectorAll<HTMLAnchorElement>('a.manual-link, a.manual-route')) {
    const target = linkTarget(link)
    if (target === null) {
      const text = document.createElement('span')
      text.className = 'manual-route-text'
      text.append(...link.childNodes)
      link.replaceWith(text)
      continue
    }
    link.href = router.resolve(target).href
  }
}

// The content is re-created when the chapter or the workspace its links point to changes.
/** The chapter body without its leading Markdown title: the view renders the title as its own <h1>. */
const body = computed(() => {
  const html = chapter.value?.html.replace(/^\s*<h1[^>]*>[\s\S]*?<\/h1>\s*/, '') ?? ''
  // The summary is shown as the lead; when it is the opening paragraph itself, that paragraph is not repeated.
  const first = /^<p>([\s\S]*?)<\/p>\s*/.exec(html)
  const summary = chapter.value?.summary.replace(/\s+/g, ' ').trim() ?? ''
  return first && summary !== '' && plainText(first[1]) === summary
    ? html.slice(first[0].length)
    : html
})
const contentKey = computed(() => `${loads.value}|${workspaceSlug.value ?? ''}`)
watch([contentKey, content], () => {
  if (content.value)
    enhance(content.value)
}, { flush: 'post' })

function internalLink(event: Event): HTMLElement | null {
  const el = event.target instanceof Element
    ? event.target.closest<HTMLElement>('a.manual-link, a.manual-route')
    : null
  return el && content.value?.contains(el)
    ? el
    : null
}

/** One delegated listener for the cross-references; external links keep their default behaviour. */
function onClick(event: MouseEvent): void {
  const link = internalLink(event)
  if (!link)
    return
  // Modified clicks open the href in a new tab or window as usual.
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)
    return
  event.preventDefault()
  const target = linkTarget(link)
  if (target !== null)
    void router.push(target)
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Enter' && event.key !== ' ')
    return
  const link = internalLink(event)
  if (!link)
    return
  event.preventDefault()
  const target = linkTarget(link)
  if (target !== null)
    void router.push(target)
}
</script>

<template>
  <div class="help-chapter">
    <div class="topbar">
      <nav class="breadcrumb" :aria-label="t('help.breadcrumb')" data-test="breadcrumb">
        <ol>
          <li>
            <router-link :to="{ name: 'help' }" data-test="back">
              {{ t('help.title') }}
            </router-link>
          </li>
          <li v-if="chapter && !notFound && sectionTitle" data-test="breadcrumb-section">
            {{ sectionTitle }}
          </li>
          <li v-if="chapter && !notFound" aria-current="page" class="breadcrumb-current">
            {{ chapter.title }}
          </li>
        </ol>
      </nav>
      <form role="search" class="compact-search" data-test="chapter-search" @submit.prevent="searchManual">
        <v-text-field
          v-model="searchQuery"
          :aria-label="t('help.search')"
          :placeholder="t('help.search')"
          :prepend-inner-icon="mdiMagnify"
          type="search"
          density="compact"
          hide-details
          single-line
        />
      </form>
    </div>

    <template v-if="notFound">
      <h1 class="text-h5 mb-2">
        {{ t('help.notFoundTitle') }}
      </h1>
      <p class="text-medium-emphasis mb-4" data-test="not-found">
        {{ t('help.notFound') }}
      </p>
      <v-btn :to="{ name: 'help' }" variant="tonal">
        {{ t('help.backToIndex') }}
      </v-btn>
    </template>

    <template v-else>
      <ProblemAlert :error="error" class="mb-4" />
      <div v-if="error && !chapter" class="mb-4">
        <v-btn variant="tonal" :loading="loading" data-test="retry" @click="load()">
          {{ t('common.retry') }}
        </v-btn>
      </div>
    </template>

    <v-progress-linear v-if="loading && !chapter" indeterminate color="primary" class="mb-4" data-test="loading" />

    <div v-if="chapter && !notFound" class="layout">
      <article class="sheet">
        <header class="sheet-header">
          <p class="meta">
            <span class="number-chip iw-display" data-test="number">{{ chapter.number }}</span>
            <template v-if="sectionTitle">
              <span>{{ sectionTitle }}</span>
              <span aria-hidden="true">·</span>
            </template>
            <span>{{ t('help.readingTime', { minutes: chapter.readingMinutes }) }}</span>
          </p>
          <h1 ref="heading" class="chapter-title iw-display" tabindex="-1" data-test="title">
            {{ chapter.title }}
          </h1>
          <p v-if="chapter.summary" class="lead" data-test="lead">
            {{ chapter.summary }}
          </p>
        </header>

        <v-alert v-if="showFallback" type="info" variant="tonal" density="compact" class="mb-4" data-test="fallback">
          {{ t('help.fallbackNotice') }}
        </v-alert>

        <ManualSidebar v-if="mobile" :headings="chapter.headings" class="mb-4 toc-inline" />

        <!-- Sanitized server-side (CommonMark html_input: strip, unsafe links removed); v-html is used for chapter.html only (minus its title). -->
        <!-- eslint-disable vue/no-v-html -->
        <div
          :key="contentKey"
          ref="content"
          class="manual-content"
          data-test="content"
          @click="onClick"
          @keydown="onKeydown"
          v-html="body"
        />
        <!-- eslint-enable vue/no-v-html -->

        <nav v-if="chapter.prev || chapter.next" class="pager" :aria-label="t('help.pager')">
          <router-link
            v-if="chapter.prev"
            :to="chapterLocation(chapter.prev.slug)"
            class="pager-card"
            data-test="prev"
          >
            <span class="pager-label"><span aria-hidden="true">← </span>{{ t('help.previous') }}<template v-if="prevNumber"> · {{ prevNumber }}</template></span>
            <span class="pager-title">{{ chapter.prev.title }}</span>
          </router-link>
          <span v-else aria-hidden="true" />
          <router-link
            v-if="chapter.next"
            :to="chapterLocation(chapter.next.slug)"
            class="pager-card pager-next"
            data-test="next"
          >
            <span class="pager-label"><template v-if="nextNumber">{{ nextNumber }} · </template>{{ t('help.next') }}<span aria-hidden="true"> →</span></span>
            <span class="pager-title">{{ chapter.next.title }}</span>
          </router-link>
        </nav>
      </article>

      <aside v-if="!mobile || appTarget" class="aside">
        <div class="aside-inner">
          <ManualSidebar v-if="!mobile" :headings="chapter.headings" />
          <div v-if="appTarget" class="open-card" data-test="open-in-app">
            <span class="open-title">{{ t('help.openInApp') }}</span>
            <v-btn :to="appTarget" color="primary" variant="flat" block class="open-btn" data-test="open-in-app-link">
              {{ t('help.openInAppAction', { name: chapter.title }) }}
            </v-btn>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.help-chapter {
  max-width: 1100px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.topbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 16px;
}

.breadcrumb ol {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.9375rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.breadcrumb li + li::before {
  content: '/';
  margin-inline-end: 8px;
}

.breadcrumb a {
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  color: rgb(var(--v-theme-primary));
  font-weight: 500;
  text-decoration: none;
}

.breadcrumb a:hover {
  text-decoration: underline;
}

.breadcrumb-current {
  color: rgb(var(--v-theme-on-surface));
  font-weight: 500;
}

.compact-search {
  width: 320px;
  max-width: 100%;
}

.compact-search :deep(.v-field) {
  background: rgb(var(--v-theme-surface));
}

.layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 260px;
  gap: 48px;
  align-items: start;
}

@media (max-width: 959.98px) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
  }
}

.sheet {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding: 48px 56px 40px;
  border-radius: 14px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  box-shadow: 0 1px 2px rgba(23, 32, 43, 0.06);
}

.sheet-header {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-bottom: 24px;
  border-bottom: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.6));
}

.meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin: 0;
  font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.number-chip {
  padding: 2px 8px;
  border-radius: 6px;
  font-weight: 700;
  color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.12);
}

.chapter-title {
  margin: 0;
  font-size: 2.75rem;
  font-weight: 700;
  line-height: 1.05;
  overflow-wrap: anywhere;
}

.chapter-title:focus {
  outline: none;
}

.lead {
  margin: 0;
  max-width: 62ch;
  font-size: 1.1875rem;
  line-height: 1.55;
  color: rgba(var(--v-theme-on-surface), 0.8);
}

.pager {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin-top: 20px;
}

.pager-card {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-height: 44px;
  padding: 14px 18px;
  border: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.8));
  border-radius: 10px;
  color: rgb(var(--v-theme-on-surface));
  text-decoration: none;
  min-width: 0;
}

.pager-card:hover {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
}

.pager-next {
  text-align: end;
}

.pager-label {
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.pager-title {
  font-weight: 600;
  font-size: 1.0625rem;
  color: rgb(var(--v-theme-primary));
  overflow-wrap: anywhere;
}

.aside-inner {
  position: sticky;
  top: 24px;
  max-height: calc(100vh - 48px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding-top: 8px;
}

.open-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px;
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  font-size: 0.9375rem;
}

.open-title {
  font-weight: 600;
}

.open-btn {
  min-height: 44px;
  text-transform: none;
  letter-spacing: normal;
  font-weight: 600;
}

@media (max-width: 959.98px) {
  .aside-inner {
    position: static;
    max-height: none;
    padding-top: 0;
  }
}

@media (max-width: 599.98px) {
  .help-chapter {
    gap: 16px;
  }

  .compact-search {
    width: 100%;
  }

  .sheet {
    padding: 24px 18px;
    border-radius: 12px;
  }

  .chapter-title {
    font-size: 2rem;
  }

  .lead {
    font-size: 1.0625rem;
  }

  .pager {
    grid-template-columns: minmax(0, 1fr);
  }
}

/* Rendered manual markup (classes from the laravel-manual renderer). */
.manual-content {
  overflow-wrap: anywhere;
  font-size: 1.0625rem;
  line-height: 1.7;
}

.manual-content :deep(h2) {
  font-family: 'Barlow Semi Condensed', 'Barlow', system-ui, sans-serif;
  font-size: 1.625rem;
  font-weight: 600;
  line-height: 1.2;
  margin: 2.25rem 0 0.75rem;
  scroll-margin-top: 16px;
}

.manual-content :deep(h3) {
  font-family: 'Barlow Semi Condensed', 'Barlow', system-ui, sans-serif;
  font-size: 1.25rem;
  font-weight: 600;
  line-height: 1.25;
  margin: 1.75rem 0 0.5rem;
  scroll-margin-top: 16px;
}

.manual-content :deep(> :first-child) {
  margin-top: 0;
}

.manual-content :deep(h2:focus),
.manual-content :deep(h3:focus) {
  outline: none;
}

.manual-content :deep(p),
.manual-content :deep(ul),
.manual-content :deep(ol) {
  margin-bottom: 1rem;
  max-width: 68ch;
}

.manual-content :deep(ul),
.manual-content :deep(ol) {
  padding-inline-start: 1.5rem;
}

.manual-content :deep(li + li) {
  margin-top: 0.3rem;
}

.manual-content :deep(code) {
  font-size: 0.875em;
  padding: 0.1em 0.35em;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.07);
}

.manual-content :deep(pre) {
  overflow-x: auto;
  max-width: 100%;
  padding: 12px 14px;
  margin-bottom: 1rem;
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.manual-content :deep(pre code) {
  padding: 0;
  background: none;
}

.manual-content :deep(img) {
  max-width: 100%;
  height: auto;
}

.manual-content :deep(a) {
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
}

.manual-content :deep(a:focus-visible) {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
  border-radius: 2px;
}

.manual-content :deep(.manual-icon) {
  display: inline-flex;
  vertical-align: -0.15em;
}

.manual-content :deep(.manual-icon svg) {
  width: 1.15em;
  height: 1.15em;
  fill: currentColor;
}

/* Tables: a quiet frame, a small-caps header row and hairline row rules. */
.manual-content :deep(.manual-table-wrap) {
  overflow-x: auto;
  max-width: 100%;
  margin: 0.25rem 0 1.25rem;
  border: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.65));
  border-radius: 10px;
}

.manual-content :deep(.manual-table-wrap table) {
  border-collapse: collapse;
  width: 100%;
  overflow-wrap: normal;
  font-size: 0.9375rem;
  line-height: 1.5;
}

.manual-content :deep(.manual-table-wrap th),
.manual-content :deep(.manual-table-wrap td) {
  padding: 12px 16px;
  text-align: start;
  vertical-align: top;
  border-bottom: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.5));
}

.manual-content :deep(.manual-table-wrap tr:last-child td) {
  border-bottom: 0;
}

.manual-content :deep(.manual-table-wrap th) {
  font-size: 0.8125rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  background: rgba(var(--v-theme-on-surface), 0.035);
}

/* The first column usually names the thing the row explains. */
.manual-content :deep(.manual-table-wrap td:first-child) {
  font-weight: 600;
  white-space: nowrap;
}

.manual-content :deep(.manual-table-wrap tbody tr:hover) {
  background: rgba(var(--v-theme-primary), 0.03);
}

/* Callouts: tinted boxes in the theme palette, with an icon and a label added by the view. */
.manual-content :deep(.manual-callout) {
  --callout: var(--v-theme-secondary);
  display: flex;
  gap: 14px;
  margin: 0.25rem 0 1.25rem;
  padding: 16px 18px;
  border-radius: 10px;
  /* 8 % keeps the coloured label at 4.5:1 or more in both themes. */
  background: rgba(var(--callout), 0.08);
  font-size: 1rem;
  line-height: 1.55;
}

.manual-content :deep(.manual-callout[data-type='tip']) {
  --callout: var(--v-theme-success);
}

.manual-content :deep(.manual-callout[data-type='warning']) {
  --callout: var(--v-theme-warning);
}

.manual-content :deep(.manual-callout[data-type='note']) {
  --callout: var(--v-theme-info);
}

.manual-content :deep(.manual-callout[data-type='steps']) {
  --callout: var(--v-theme-primary);
}

.manual-content :deep(.manual-callout-icon) {
  flex: none;
  width: 22px;
  height: 22px;
  margin-top: 1px;
  fill: rgb(var(--callout));
}

.manual-content :deep(.manual-callout-body) {
  min-width: 0;
  flex: 1;
}

.manual-content :deep(.manual-callout-label) {
  color: rgb(var(--callout));
}

.manual-content :deep(.manual-callout-body > :last-child) {
  margin-bottom: 0;
}

.manual-content :deep(.manual-callout p),
.manual-content :deep(.manual-callout ol),
.manual-content :deep(.manual-callout ul) {
  max-width: none;
}
</style>
