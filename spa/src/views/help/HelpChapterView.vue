<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import type { ApiError } from '../../api'
import type { components } from '../../api/schema'
import { mdiArrowLeft, mdiChevronLeft, mdiChevronRight } from '@mdi/js'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { api } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { chapterLocation, prefersReducedMotion } from '../../components/help/manual'
import { manualIcon } from '../../components/help/manualIcons'
import ManualSidebar from '../../components/help/ManualSidebar.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useSession } from '../../stores/session'

type Chapter = components['schemas']['ChapterResource']

const props = defineProps<{
  slug: string
}>()

const { t, locale } = useI18n()
const router = useRouter()
const session = useSession()
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

async function load(moved = false): Promise<void> {
  const mine = ++sequence
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
      if (moved)
        void showTop()
    }
  }
  catch (e) {
    if (mine === sequence) {
      chapter.value = null
      error.value = failure(e)
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
const body = computed(() => chapter.value?.html.replace(/^\s*<h1[^>]*>[\s\S]*?<\/h1>\s*/, '') ?? '')
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
    <v-btn
      :to="{ name: 'help' }"
      variant="text"
      density="comfortable"
      :prepend-icon="mdiArrowLeft"
      class="mb-2 back"
      data-test="back"
    >
      {{ t('help.backToIndex') }}
    </v-btn>

    <template v-if="notFound">
      <h1 class="text-h5 mb-2">
        {{ t('help.notFoundTitle') }}
      </h1>
      <p class="text-medium-emphasis mb-4" data-test="not-found">
        {{ t('help.notFound') }}
      </p>
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
      <article class="article">
        <header class="mb-4">
          <p class="text-overline mb-0 text-medium-emphasis">
            <span data-test="number">{{ chapter.number }}</span>
            · {{ t('help.readingTime', { minutes: chapter.readingMinutes }) }}
          </p>
          <h1 ref="heading" class="text-h4 chapter-title" tabindex="-1" data-test="title">
            {{ chapter.title }}
          </h1>
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

        <nav class="pager mt-8" :aria-label="t('help.pager')">
          <v-btn
            v-if="chapter.prev"
            :to="chapterLocation(chapter.prev.slug)"
            variant="tonal"
            :prepend-icon="mdiChevronLeft"
            class="pager-btn"
            data-test="prev"
          >
            <span class="pager-text">
              <span class="pager-label">{{ t('help.previous') }}</span>
              <span class="pager-title">{{ chapter.prev.title }}</span>
            </span>
          </v-btn>
          <span v-else />
          <v-btn
            v-if="chapter.next"
            :to="chapterLocation(chapter.next.slug)"
            variant="tonal"
            :append-icon="mdiChevronRight"
            class="pager-btn pager-next"
            data-test="next"
          >
            <span class="pager-text">
              <span class="pager-label">{{ t('help.next') }}</span>
              <span class="pager-title">{{ chapter.next.title }}</span>
            </span>
          </v-btn>
        </nav>
      </article>

      <aside v-if="!mobile" class="aside">
        <ManualSidebar :headings="chapter.headings" class="toc-sticky" />
      </aside>
    </div>
  </div>
</template>

<style scoped>
.help-chapter {
  max-width: 1100px;
}

.layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 240px;
  gap: 40px;
  align-items: start;
}

@media (max-width: 959.98px) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
  }
}

.article {
  min-width: 0;
  max-width: 760px;
}

.chapter-title {
  overflow-wrap: anywhere;
}

.chapter-title:focus {
  outline: none;
}

.toc-sticky {
  position: sticky;
  top: 24px;
  max-height: calc(100vh - 48px);
  overflow-y: auto;
}

.pager {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.pager-btn {
  height: auto !important;
  min-height: 48px;
  padding-block: 6px;
  max-width: 100%;
  text-transform: none;
}

.pager-next {
  margin-inline-start: auto;
}

.pager-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  white-space: normal;
  text-align: start;
  min-width: 0;
}

.pager-next .pager-text {
  align-items: flex-end;
  text-align: end;
}

.pager-label {
  font-size: 0.75rem;
  opacity: 0.75;
}

.pager-title {
  overflow-wrap: anywhere;
}

/* Rendered manual markup (classes from the laravel-manual renderer). */
.manual-content {
  overflow-wrap: anywhere;
  line-height: 1.65;
}

.manual-content :deep(h2) {
  font-size: 1.375rem;
  font-weight: 600;
  margin: 2rem 0 0.75rem;
  scroll-margin-top: 16px;
}

.manual-content :deep(h3) {
  font-size: 1.125rem;
  font-weight: 600;
  margin: 1.5rem 0 0.5rem;
  scroll-margin-top: 16px;
}

.manual-content :deep(h2:focus),
.manual-content :deep(h3:focus) {
  outline: none;
}

.manual-content :deep(p),
.manual-content :deep(ul),
.manual-content :deep(ol) {
  margin-bottom: 0.875rem;
}

.manual-content :deep(ul),
.manual-content :deep(ol) {
  padding-inline-start: 1.5rem;
}

.manual-content :deep(li + li) {
  margin-top: 0.25rem;
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
  border-radius: 6px;
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

.manual-content :deep(.manual-table-wrap) {
  overflow-x: auto;
  max-width: 100%;
  margin-bottom: 1rem;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
}

.manual-content :deep(.manual-table-wrap table) {
  border-collapse: collapse;
  width: 100%;
  overflow-wrap: normal;
}

.manual-content :deep(.manual-table-wrap th),
.manual-content :deep(.manual-table-wrap td) {
  padding: 8px 12px;
  text-align: start;
  vertical-align: top;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.manual-content :deep(.manual-table-wrap tr:last-child td) {
  border-bottom: 0;
}

.manual-content :deep(.manual-table-wrap th) {
  font-weight: 600;
  background: rgba(var(--v-theme-on-surface), 0.04);
}

/* Callouts take their colour from the theme palette, so light and dark both follow it. */
.manual-content :deep(.manual-callout) {
  --callout: var(--v-theme-secondary);
  margin: 0 0 1rem;
  padding: 12px 16px;
  border-inline-start: 4px solid rgb(var(--callout));
  border-radius: 6px;
  background: rgba(var(--callout), 0.1);
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

.manual-content :deep(.manual-callout > :last-child) {
  margin-bottom: 0;
}
</style>
