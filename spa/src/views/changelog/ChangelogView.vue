<script setup lang="ts">
import type { ApiError } from '../../api'
import type { components } from '../../api/schema'
import { mdiChevronDown } from '@mdi/js'
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useChangelogNews } from '../../stores/changelog'
import { useSession } from '../../stores/session'

type Release = components['schemas']['ReleaseResource']
type Group = Release['groups'][number]

interface Entry {
  release: Release
  /** The groups left by the area filter. */
  groups: Group[]
}

const { t, locale } = useI18n()
const session = useSession()
const news = useChangelogNews()
const failure = useApiFailure()

const releases = ref<Release[]>([])
const page = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loaded = ref(false)
const error = ref<ApiError | null>(null)

/** The selected area (group name); null shows every group. */
const filter = ref<string | null>(null)
/** Older releases the user has expanded. */
const expanded = reactive(new Set<string>())
/** The release last jumped to from the version index. */
const selected = ref<string | null>(null)

/** The newest published release; an Unreleased section above it is not a version anyone runs. */
const latest = computed(() => releases.value.find(r => !r.unreleased) ?? null)

async function load(): Promise<void> {
  if (loading.value)
    return
  loading.value = true
  error.value = null
  try {
    const next = page.value + 1
    const { data } = await api.GET('/changelog', {
      params: {
        query: { page: next },
      },
    })
    if (data) {
      releases.value = [
        ...releases.value,
        ...data.data,
      ]
      page.value = data.meta.current_page
      lastPage.value = data.meta.last_page
      loaded.value = true
      if (latest.value)
        news.markSeen(latest.value.version)
    }
  }
  catch (e) {
    error.value = failure(e)
  }
  finally {
    loading.value = false
  }
}

onMounted(load)

// Release texts are served in the request locale, so a language switch starts over; group names are
// translated too, so the filter goes with them.
watch(locale, () => {
  releases.value = []
  page.value = 0
  lastPage.value = 1
  loaded.value = false
  filter.value = null
  selected.value = null
  expanded.clear()
  void load()
})

/**
 * Area names in order of first appearance. With several releases only the
 * areas that recur in at least two of them become filters: a one-off group
 * (the first release has many) would otherwise bury the useful ones.
 */
const groupNames = computed(() => {
  const counts = new Map<string, number>()
  for (const release of releases.value) {
    for (const name of new Set(release.groups.map(g => g.name)))
      counts.set(name, (counts.get(name) ?? 0) + 1)
  }
  const names = [
    ...counts.keys(),
  ]
  if (releases.value.length < 2)
    return names
  return names.filter(name => counts.get(name)! >= 2)
})

const entries = computed<Entry[]>(() => {
  const area = filter.value
  return releases.value
    .map(release => ({
      release,
      groups: area === null
        ? release.groups
        : release.groups.filter(g => g.name === area),
    }))
    .filter(e => area === null || e.groups.length > 0)
})

const current = computed(() => selected.value ?? latest.value?.version ?? entries.value[0]?.release.version ?? null)

const CALENDAR_DATE = /^\d{4}-\d{2}-\d{2}$/

/** A bare release date is a calendar day and is not shifted between zones; a timestamp is shown in the workspace zone. */
function formatDate(value: string, style: 'long' | 'medium' = 'long'): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime()))
    return value
  try {
    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: style,
      timeZone: CALENDAR_DATE.test(value)
        ? 'UTC'
        : (session.current?.timezone ?? 'UTC'),
    }).format(date)
  }
  catch {
    return value
  }
}

function showFallback(release: Release): boolean {
  return release.fallback && locale.value !== 'en'
}

function anchor(release: Release): string {
  return `v${release.version}`
}

function itemCount(groups: Group[]): number {
  return groups.reduce((sum, g) => sum + g.items.length, 0)
}

/** The summary as one plain line for a collapsed row; the HTML is parsed inertly, never rendered. */
function plainSummary(entry: Entry): string {
  const html = entry.release.summary_html
  if (html) {
    const text = new DOMParser().parseFromString(html, 'text/html').body.textContent?.trim()
    if (text)
      return text
  }
  return entry.groups.map(g => g.name).join(' · ')
}

// A stable colour per area name, from the theme's text-safe status colours.
const TAG_COLORS = [
  'primary',
  'success',
  'maintenance',
  'info',
  'warning',
] as const

function tagStyle(name: string): Record<string, string> {
  let hash = 0
  for (const char of name)
    hash = (hash * 31 + char.charCodeAt(0)) >>> 0
  return { '--cl-tag': `var(--v-theme-${TAG_COLORS[hash % TAG_COLORS.length]})` }
}

function isExpanded(release: Release): boolean {
  return expanded.has(release.version)
}

function toggle(release: Release): void {
  if (expanded.has(release.version))
    expanded.delete(release.version)
  else
    expanded.add(release.version)
}

async function jump(release: Release): Promise<void> {
  selected.value = release.version
  if (release.version !== latest.value?.version)
    expanded.add(release.version)
  await nextTick()
  const target = document.getElementById(anchor(release))
  if (!target)
    return
  const reduce = typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  target.scrollIntoView?.({
    behavior: reduce ? 'auto' : 'smooth',
    block: 'start',
  })
  target.focus({ preventScroll: true })
}
</script>

<template>
  <div class="changelog">
    <header class="cl-head">
      <div class="cl-intro">
        <p class="cl-eyebrow">
          {{ t('changelog.eyebrow') }}
        </p>
        <h1 class="cl-title">
          {{ t('changelog.title') }}
        </h1>
        <p class="cl-lead">
          {{ t('changelog.intro') }}
          <template v-if="latest">
            <span data-test="current-version">{{ t('changelog.latestVersion') }} <strong>{{ latest.version }}</strong></span>
          </template>
        </p>
      </div>
      <div
        v-if="groupNames.length > 1"
        role="group"
        :aria-label="t('changelog.filterLabel')"
        class="cl-filters"
      >
        <button
          type="button"
          class="cl-chip"
          data-test="filter"
          :aria-pressed="filter === null"
          @click="filter = null"
        >
          {{ t('changelog.filterAll') }}
        </button>
        <button
          v-for="name in groupNames"
          :key="name"
          type="button"
          class="cl-chip"
          data-test="filter"
          :aria-pressed="filter === name"
          @click="filter = name"
        >
          {{ name }}
        </button>
      </div>
    </header>

    <ProblemAlert :error="error" class="mb-4" />

    <div v-if="entries.length > 0" class="cl-layout">
      <nav class="cl-index" :aria-label="t('changelog.versions')">
        <ol>
          <li v-for="{ release } in entries" :key="release.version">
            <a
              :href="`#${anchor(release)}`"
              :aria-current="release.version === current ? 'true' : undefined"
              data-test="index-link"
              @click.prevent="jump(release)"
            >
              <span :class="{ yanked: release.yanked }">{{ release.version }}</span>
              <span v-if="release.unreleased" class="cl-index-date">· {{ t('changelog.unreleased') }}</span>
              <span v-else-if="release.date" class="cl-index-date">· {{ formatDate(release.date, 'medium') }}</span>
            </a>
          </li>
        </ol>
      </nav>

      <div class="cl-list">
        <template v-for="entry in entries" :key="entry.release.version">
          <!-- The newest published release: open, with a dark band. -->
          <article
            v-if="entry.release.version === latest?.version"
            :id="anchor(entry.release)"
            class="cl-latest"
            tabindex="-1"
            data-test="release"
          >
            <header class="cl-band">
              <h2 class="cl-band-version">
                <component
                  :is="entry.release.url ? 'a' : 'span'"
                  :href="entry.release.url ?? undefined"
                  :target="entry.release.url ? '_blank' : undefined"
                  :rel="entry.release.url ? 'noopener noreferrer' : undefined"
                  class="version"
                  :class="{ yanked: entry.release.yanked }"
                >
                  {{ entry.release.version }}
                </component>
              </h2>
              <div class="cl-band-meta">
                <div class="cl-band-line">
                  <span class="cl-pill" data-test="latest-pill">{{ t('changelog.latest') }}</span>
                  <time v-if="entry.release.date" :datetime="entry.release.date">{{ formatDate(entry.release.date) }}</time>
                  <span
                    v-if="entry.release.yanked"
                    class="cl-yanked cl-yanked--band"
                    data-test="yanked"
                    :title="t('changelog.yankedHint')"
                  >{{ t('changelog.yanked') }}</span>
                </div>
                <!-- eslint-disable-next-line vue/no-v-html -- sanitized server-side: raw HTML is stripped and unsafe links removed -->
                <div v-if="entry.release.summary_html" class="prose cl-band-lead" v-html="entry.release.summary_html" />
              </div>
            </header>
            <div class="cl-body">
              <v-alert v-if="showFallback(entry.release)" type="info" density="compact" class="mt-4" data-test="fallback">
                {{ t('changelog.fallbackNotice') }}
              </v-alert>
              <section v-for="group in entry.groups" :key="group.name" class="cl-group" data-test="group">
                <h3 class="cl-group-label">
                  <span class="cl-tag" :style="tagStyle(group.name)">{{ group.name }}</span>
                </h3>
                <div class="cl-group-items">
                  <!-- eslint-disable vue/no-v-html -- sanitized server-side: raw HTML is stripped and unsafe links removed -->
                  <div v-if="group.summary_html" class="prose" v-html="group.summary_html" />
                  <ul class="items">
                    <li v-for="(item, i) in group.items" :key="i" class="prose" v-html="item.html" />
                  </ul>
                  <!-- eslint-enable vue/no-v-html -->
                </div>
              </section>
            </div>
          </article>

          <!-- Unreleased and older releases: a disclosure row. -->
          <article
            v-else
            :id="anchor(entry.release)"
            class="cl-row"
            :class="{ 'cl-row--unreleased': entry.release.unreleased }"
            tabindex="-1"
            data-test="release"
          >
            <h2 class="cl-row-heading">
              <button
                type="button"
                class="cl-row-toggle"
                :aria-expanded="isExpanded(entry.release)"
                :aria-controls="`${anchor(entry.release)}-body`"
                data-test="release-toggle"
                @click="toggle(entry.release)"
              >
                <span class="cl-row-version" :class="{ yanked: entry.release.yanked }">{{ entry.release.version }}</span>
                <span class="cl-row-text">
                  <span class="cl-row-summary">{{ plainSummary(entry) }}</span>
                  <span class="cl-row-meta">
                    <span v-if="entry.release.unreleased" class="cl-unreleased">{{ t('changelog.unreleased') }}</span>
                    <time v-else-if="entry.release.date" :datetime="entry.release.date">{{ formatDate(entry.release.date) }}</time>
                    · <span data-test="change-count">{{ t('changelog.changes', itemCount(entry.groups)) }}</span>
                    <span
                      v-if="entry.release.yanked"
                      class="cl-yanked"
                      data-test="yanked"
                      :title="t('changelog.yankedHint')"
                    >{{ t('changelog.yanked') }}</span>
                  </span>
                </span>
                <span class="cl-row-action">
                  <span class="cl-row-action-text">{{ isExpanded(entry.release) ? t('changelog.hideDetails') : t('changelog.showDetails') }}</span>
                  <svg class="cl-chevron" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path :d="mdiChevronDown" fill="currentColor" /></svg>
                </span>
              </button>
            </h2>
            <div v-show="isExpanded(entry.release)" :id="`${anchor(entry.release)}-body`" class="cl-body" data-test="release-body">
              <v-alert v-if="showFallback(entry.release)" type="info" density="compact" class="mt-4" data-test="fallback">
                {{ t('changelog.fallbackNotice') }}
              </v-alert>
              <!-- eslint-disable vue/no-v-html -- sanitized server-side: raw HTML is stripped and unsafe links removed -->
              <section v-for="group in entry.groups" :key="group.name" class="cl-group" data-test="group">
                <h3 class="cl-group-label">
                  <span class="cl-tag" :style="tagStyle(group.name)">{{ group.name }}</span>
                </h3>
                <div class="cl-group-items">
                  <div v-if="group.summary_html" class="prose" v-html="group.summary_html" />
                  <ul class="items">
                    <li v-for="(item, i) in group.items" :key="i" class="prose" v-html="item.html" />
                  </ul>
                </div>
              </section>
              <!-- eslint-enable vue/no-v-html -->
              <p v-if="entry.release.url" class="cl-release-link">
                <a :href="entry.release.url" target="_blank" rel="noopener noreferrer">{{ t('changelog.releasePage') }}</a>
              </p>
            </div>
          </article>
        </template>

        <div v-if="page < lastPage">
          <v-btn variant="tonal" :loading="loading" data-test="load-more" @click="load">
            {{ t('common.loadMore') }}
          </v-btn>
        </div>
      </div>
    </div>

    <div v-if="error && releases.length === 0">
      <v-btn variant="tonal" :loading="loading" data-test="retry" @click="load">
        {{ t('common.retry') }}
      </v-btn>
    </div>

    <p v-if="loaded && releases.length === 0" class="text-medium-emphasis">
      {{ t('changelog.empty') }}
    </p>
  </div>
</template>

<style scoped>
/*
 * The latest release's band is dark in both themes; its colours are fixed per theme rather than theme
 * tokens, so they live here. Area tags tint the theme's text-safe colour (4.5:1 over the tint).
 */
.changelog {
  --cl-band-bg: #17202b;
  --cl-band-fg: #e3e8ef;
  --cl-band-strong: #ffffff;
  --cl-band-pill: #8fa7f4;
  --cl-band-pill-fg: #17202b;
  --cl-tint: 0.07;
  --cl-muted: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));

  max-width: 1080px;
  margin: 8px auto 0;
  display: flex;
  flex-direction: column;
  gap: 32px;
}

:global(.v-theme--dark) .changelog {
  --cl-band-bg: #0b1118;
  --cl-tint: 0.16;
}

.cl-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 20px;
}

.cl-intro {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cl-eyebrow {
  margin: 0;
  font-family: 'Barlow Semi Condensed', 'Barlow', system-ui, sans-serif;
  font-weight: 600;
  font-size: 0.8125rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary));
}

.cl-title {
  margin: 0;
  font-family: 'Barlow Semi Condensed', 'Barlow', system-ui, sans-serif;
  font-weight: 600;
  font-size: clamp(2rem, 5vw, 3rem);
  line-height: 1.05;
}

.cl-lead {
  margin: 0;
  font-size: 1.0625rem;
  color: var(--cl-muted);
}

.cl-lead strong {
  color: rgb(var(--v-theme-on-surface));
}

.cl-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

@media (max-width: 599px) {
  .cl-filters {
    flex-wrap: nowrap;
    overflow-x: auto;
    margin: 0 -16px;
    padding: 0 16px 4px;
    scrollbar-width: none;
  }

  .cl-chip {
    flex: none;
  }
}

.cl-chip {
  min-height: 44px;
  padding: 0 16px;
  border-radius: 999px;
  border: 1px solid rgba(var(--v-border-color), 0.45);
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  font: inherit;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
}

.cl-chip:hover {
  border-color: rgb(var(--v-theme-on-surface));
}

.cl-chip[aria-pressed='true'] {
  border-color: rgb(var(--v-theme-on-surface));
  background: rgb(var(--v-theme-on-surface));
  color: rgb(var(--v-theme-surface));
  font-weight: 600;
}

.cl-layout {
  display: grid;
  grid-template-columns: 180px minmax(0, 1fr);
  gap: 40px;
  align-items: start;
}

.cl-index {
  position: sticky;
  top: 24px;
}

.cl-index ol {
  list-style: none;
  margin: 0;
  padding: 4px 0 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  border-left: 2px solid rgba(var(--v-border-color), 0.4);
}

.cl-index a {
  display: block;
  margin-left: -2px;
  padding: 8px 14px;
  border-left: 2px solid transparent;
  color: var(--cl-muted);
  text-decoration: none;
  overflow-wrap: anywhere;
}

.cl-index a:hover {
  color: rgb(var(--v-theme-on-surface));
}

.cl-index a[aria-current='true'] {
  border-left-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-surface));
  font-weight: 600;
}

.cl-index-date {
  margin-left: 4px;
  font-size: 0.875rem;
  font-weight: 400;
  color: var(--cl-muted);
}

.cl-list {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
}

.cl-latest,
.cl-row {
  scroll-margin-top: 72px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
}

.cl-latest {
  border-radius: 14px;
  box-shadow: 0 1px 2px rgba(23, 32, 43, 0.06), 0 10px 30px rgba(23, 32, 43, 0.07);
}

.cl-latest:focus,
.cl-row:focus {
  outline: none;
}

.cl-latest:focus-visible,
.cl-row:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.cl-band {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px 28px;
  padding: 28px 32px;
  background: var(--cl-band-bg);
  color: var(--cl-band-fg);
}

.cl-band-version {
  margin: 0;
  font-family: 'Barlow Semi Condensed', 'Barlow', system-ui, sans-serif;
  font-weight: 600;
  font-size: clamp(2.5rem, 7vw, 3.5rem);
  line-height: 1;
  color: var(--cl-band-strong);
}

.cl-band-meta {
  flex: 1 1 260px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.cl-band-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  font-size: 0.875rem;
}

.cl-pill {
  padding: 2px 10px;
  border-radius: 999px;
  background: var(--cl-band-pill);
  color: var(--cl-band-pill-fg);
  font-weight: 600;
}

.cl-band-lead {
  font-size: 1.125rem;
  line-height: 1.45;
}

.cl-band-lead :deep(p) {
  margin: 0;
}

.cl-band-lead :deep(a),
.cl-band-version a {
  color: var(--cl-band-strong);
}

.cl-body {
  display: flex;
  flex-direction: column;
  padding: 8px 32px 24px;
}

.cl-group {
  display: grid;
  grid-template-columns: 180px minmax(0, 1fr);
  gap: 24px;
  padding: 20px 0;
  border-bottom: 1px solid var(--iw-line);
}

.cl-group:last-child {
  border-bottom: 0;
}

.cl-group-label {
  margin: 2px 0 0;
  display: flex;
  align-items: flex-start;
}

.cl-tag {
  padding: 4px 10px;
  border-radius: 6px;
  background: rgba(var(--cl-tag), var(--cl-tint));
  color: rgb(var(--cl-tag));
  font-size: 0.8125rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  overflow-wrap: anywhere;
}

.cl-group-items {
  min-width: 0;
  font-size: 1rem;
  line-height: 1.6;
}

.items {
  margin: 0;
  padding-left: 18px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.cl-row {
  border-radius: 12px;
  border: 1px solid rgba(var(--v-border-color), 0.5);
}

.cl-row--unreleased {
  border-style: dashed;
}

.cl-row-heading {
  margin: 0;
  font: inherit;
}

.cl-row-toggle {
  display: grid;
  grid-template-columns: minmax(90px, auto) minmax(0, 1fr) auto;
  gap: 20px;
  align-items: center;
  width: 100%;
  min-height: 44px;
  padding: 18px 24px;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}

.cl-row-toggle:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.cl-row-toggle:focus-visible {
  outline-offset: -2px;
}

.cl-row-version {
  font-family: 'Barlow Semi Condensed', 'Barlow', system-ui, sans-serif;
  font-weight: 600;
  font-size: 1.75rem;
  line-height: 1.1;
  overflow-wrap: anywhere;
}

.cl-row-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.cl-row-summary {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  font-size: 1rem;
}

.cl-row-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 6px;
  font-size: 0.875rem;
  color: var(--cl-muted);
}

.cl-row-action {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.875rem;
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
}

.cl-chevron {
  transition: transform 0.15s ease;
}

[aria-expanded='true'] .cl-chevron {
  transform: rotate(180deg);
}

.cl-row .cl-body {
  border-top: 1px solid var(--iw-line);
  padding-top: 0;
}

/* Open, the row shows its whole summary instead of repeating it in the body. */
[aria-expanded='true'] .cl-row-summary {
  display: block;
  overflow: visible;
}

.cl-unreleased {
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}

.cl-yanked {
  padding: 0 8px;
  border-radius: 999px;
  background: rgba(var(--v-theme-error), var(--cl-tint));
  color: rgb(var(--v-theme-error));
  font-weight: 600;
}

.cl-yanked--band {
  background: #f4878b;
  color: #17202b;
}

.cl-release-link {
  margin: 4px 0 0;
  font-size: 0.875rem;
}

.version {
  color: inherit;
  text-decoration: none;
  overflow-wrap: anywhere;
}

a.version:hover {
  text-decoration: underline;
}

.yanked {
  text-decoration: line-through;
}

.prose {
  overflow-wrap: anywhere;
}

.prose :deep(pre) {
  overflow-x: auto;
}

@media (max-width: 959.98px) {
  .cl-layout {
    grid-template-columns: minmax(0, 1fr);
    gap: 16px;
  }

  .cl-index {
    position: static;
  }

  .cl-index ol {
    flex-direction: row;
    gap: 4px;
    padding: 0 0 4px;
    overflow-x: auto;
    border-left: 0;
    border-bottom: 2px solid rgba(var(--v-border-color), 0.4);
  }

  .cl-index li {
    flex: none;
  }

  .cl-index a {
    margin: 0 0 -6px;
    padding: 10px 12px;
    border-left: 0;
    border-bottom: 2px solid transparent;
    white-space: nowrap;
  }

  .cl-index a[aria-current='true'] {
    border-bottom-color: rgb(var(--v-theme-primary));
  }

  .cl-index-date {
    display: none;
  }
}

@media (max-width: 599.98px) {
  .cl-band {
    padding: 22px 20px;
  }

  .cl-body {
    padding: 4px 20px 20px;
  }

  .cl-group {
    grid-template-columns: minmax(0, 1fr);
    gap: 10px;
    padding: 16px 0;
  }

  .cl-row-toggle {
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 6px 12px;
    padding: 16px 20px;
  }

  .cl-row-text {
    grid-column: 1 / -1;
    grid-row: 2;
  }

  .cl-row-action-text {
    display: none;
  }
}
</style>
