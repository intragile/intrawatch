<script setup lang="ts">
import type { ApiError } from '../../api'
import type { components, paths } from '../../api/schema'
import { mdiArrowRight, mdiChevronRight } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { chapterLocation, plainText } from '../../components/help/manual'
import ManualSearch from '../../components/help/ManualSearch.vue'
import { useApiFailure } from '../../composables/useApiFailure'

type Index = paths['/manual']['get']['responses'][200]['content']['application/json']
type Section = Index['data'][number]
type Release = components['schemas']['ReleaseResource']

interface PopularLink {
  label: string
  slug: string
  anchor?: string
}

const { t, tm, rt, locale } = useI18n()
const route = useRoute()
const failure = useApiFailure()

const manual = ref<Index | null>(null)
const loading = ref(false)
const error = ref<ApiError | null>(null)
const latest = ref<Release | null>(null)

/** A query handed over by the compact search of a chapter page (`/help?q=…`). */
const initialQuery = typeof route.query.q === 'string'
  ? route.query.q
  : ''

let sequence = 0
let latestSequence = 0

/** Only the latest request counts: a language switch during a load must not show the old language. */
async function load(): Promise<void> {
  const mine = ++sequence
  loading.value = true
  error.value = null
  try {
    const { data } = await api.GET('/manual')
    if (mine === sequence && data)
      manual.value = data
  }
  catch (e) {
    if (mine === sequence)
      error.value = failure(e)
  }
  finally {
    if (mine === sequence)
      loading.value = false
  }
}

/** The newest release for the "What's new" card. Optional: any failure (or no release yet) hides the card. */
async function loadLatest(): Promise<void> {
  const mine = ++latestSequence
  try {
    const { data } = await api.GET('/changelog/latest')
    if (mine === latestSequence)
      latest.value = data?.data ?? null
  }
  catch {
    if (mine === latestSequence)
      latest.value = null
  }
}

onMounted(() => {
  void load()
  void loadLatest()
})

// Section and chapter titles (and the release summary) are served in the request locale, so a language switch reloads them.
watch(locale, () => {
  manual.value = null
  void load()
  void loadLatest()
})

const chapterCount = computed(() => manual.value?.data.reduce((n, s) => n + s.chapters.length, 0) ?? 0)

/** The first chapter of the first section, offered as the place to start. */
const featured = computed(() => manual.value?.data[0]?.chapters[0] ?? null)

/** The sections with their chapters, minus the featured one; a section left empty is not listed. */
const sections = computed(() => (manual.value?.data ?? [])
  .map((section: Section, i) => ({
    ...section,
    ordinal: String(i + 1).padStart(2, '0'),
    chapters: i === 0
      ? section.chapters.slice(1)
      : section.chapters,
  }))
  .filter(section => section.chapters.length > 0))

const slugs = computed(() => new Set(manual.value?.data.flatMap(s => s.chapters.map(c => c.slug)) ?? []))

/** The fixed quick links of the hero; one whose chapter is not in the loaded tree is left out. */
const popular = computed<PopularLink[]>(() => {
  const raw = tm('help.popular') as unknown
  if (!Array.isArray(raw))
    return []
  return raw
    .map((item: Record<string, unknown>) => ({
      label: rt(item.label as Parameters<typeof rt>[0]),
      slug: rt(item.slug as Parameters<typeof rt>[0]),
      anchor: item.anchor === undefined
        ? undefined
        : rt(item.anchor as Parameters<typeof rt>[0]),
    }))
    .filter(link => slugs.value.has(link.slug))
})

const latestSummary = computed(() => plainText(latest.value?.summary_html))

const latestDate = computed(() => {
  const date = latest.value?.date
  if (!date)
    return null
  const parsed = new Date(`${date.slice(0, 10)}T00:00:00Z`)
  if (Number.isNaN(parsed.getTime()))
    return null
  return new Intl.DateTimeFormat(locale.value, { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(parsed)
})
</script>

<template>
  <div class="help-index">
    <section class="hero" aria-labelledby="help-hero-title">
      <span class="ring ring-outer" aria-hidden="true" />
      <span class="ring ring-inner" aria-hidden="true" />
      <div class="hero-inner">
        <p class="eyebrow iw-display" data-test="eyebrow">
          {{ t('help.title') }}<template v-if="manual">
            · {{ t('help.chapterCount', chapterCount) }}
          </template>
        </p>
        <h1 id="help-hero-title" class="hero-title iw-display">
          {{ t('help.heroTitle') }}
        </h1>

        <ManualSearch hero :initial-query="initialQuery" class="hero-search" />

        <nav v-if="popular.length > 0" class="popular" aria-labelledby="help-popular-label" data-test="popular">
          <span id="help-popular-label">{{ t('help.popularLabel') }}</span>
          <router-link
            v-for="link in popular"
            :key="link.label"
            :to="chapterLocation(link.slug, link.anchor)"
            class="popular-link"
            data-test="popular-link"
          >
            {{ link.label }}
          </router-link>
        </nav>
      </div>
    </section>

    <div class="body">
      <div v-if="error || loading || (manual?.fallback && manual.locale !== locale)" class="notices">
        <v-progress-linear v-if="loading && !manual" indeterminate color="primary" class="mb-4" data-test="loading" />
        <ProblemAlert :error="error" class="mb-4" />
        <div v-if="error && !manual" class="mb-4">
          <v-btn variant="tonal" :loading="loading" data-test="retry" @click="load">
            {{ t('common.retry') }}
          </v-btn>
        </div>
        <v-alert
          v-if="manual && manual.fallback && manual.locale !== locale"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
          data-test="fallback"
        >
          {{ t('help.indexFallbackNotice') }}
        </v-alert>
      </div>

      <div v-if="featured || latest" class="highlights" :class="{ 'highlights--solo': !latest || !featured }">
        <router-link
          v-if="featured"
          :to="chapterLocation(featured.slug)"
          class="card featured"
          data-test="featured"
        >
          <span class="featured-icon" aria-hidden="true">
            <v-icon :icon="mdiArrowRight" size="26" />
          </span>
          <span class="featured-text">
            <span class="kicker">{{ t('help.startHere') }} · {{ featured.number }}</span>
            <span class="featured-title iw-display">{{ featured.title }}</span>
            <span class="featured-summary">{{ featured.summary }}</span>
          </span>
        </router-link>

        <router-link v-if="latest" :to="{ name: 'changelog' }" class="card news" data-test="whats-new">
          <span class="news-head">
            <span class="news-label">{{ t('help.whatsNew') }}</span>
            <time v-if="latestDate && latest.date" :datetime="latest.date" class="news-date">{{ latestDate }}</time>
          </span>
          <span class="news-version iw-display" data-test="whats-new-version">{{ latest.version }}</span>
          <span v-if="latestSummary" class="news-summary" data-test="whats-new-summary">{{ latestSummary }}</span>
          <span class="news-more">{{ t('help.fullChangelog') }} <span aria-hidden="true">→</span></span>
          <v-icon :icon="mdiChevronRight" size="18" class="news-chevron" aria-hidden="true" />
        </router-link>
      </div>

      <section
        v-for="section in sections"
        :key="section.id"
        class="section"
        :aria-labelledby="`help-section-${section.id}`"
        data-test="section"
      >
        <header class="section-head">
          <span class="ordinal iw-display" aria-hidden="true">{{ section.ordinal }}</span>
          <h2 :id="`help-section-${section.id}`" class="section-title iw-display">
            {{ section.title }}
          </h2>
          <span class="section-count" data-test="section-count">{{ t('help.chapterCount', section.chapters.length) }}</span>
        </header>
        <ul class="chapters">
          <li v-for="chapter in section.chapters" :key="chapter.slug">
            <router-link :to="chapterLocation(chapter.slug)" class="chapter" data-test="chapter">
              <span class="chapter-number iw-display" data-test="chapter-number">{{ chapter.number }}</span>
              <span class="chapter-text">
                <span class="chapter-title">{{ chapter.title }}</span>
                <span class="chapter-summary">{{ chapter.summary }}</span>
              </span>
              <v-icon :icon="mdiChevronRight" size="18" class="chevron" aria-hidden="true" />
            </router-link>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<style scoped>
/* The hero band stays dark in both themes; its colours are its own tokens. */
.help-index {
  --help-hero-bg: #17202b;
  --help-hero-text: #e3e8ef;
  --help-hero-heading: #ffffff;
  --help-hero-accent: #8fa7f4;
  --help-hero-muted: rgba(227, 232, 239, 0.8);
  --help-hero-ring: 143, 167, 244;
  --help-card-shadow: 0 1px 2px rgba(23, 32, 43, 0.08), 0 8px 24px rgba(23, 32, 43, 0.08);
  --help-card-border: transparent;
  --help-field-ring: transparent;
}

:global(.v-theme--dark) .help-index {
  --help-hero-bg: #0b1118;
  --help-card-shadow: 0 1px 2px rgba(0, 0, 0, 0.3), 0 8px 24px rgba(0, 0, 0, 0.25);
  /* Dark surfaces sit close to the band and the page, so cards and the field get a hairline. */
  --help-card-border: rgba(154, 166, 182, 0.2);
  --help-field-ring: rgba(154, 166, 182, 0.35);
}

.hero {
  position: relative;
  overflow: hidden;
  /* Full bleed inside the page padding of the app shell. */
  margin: -24px -28px 0;
  padding: 56px 28px 72px;
  background: var(--help-hero-bg);
  color: var(--help-hero-text);
}

.ring {
  position: absolute;
  border-radius: 50%;
  pointer-events: none;
}

.ring-outer {
  right: -40px;
  top: -60px;
  width: 420px;
  height: 420px;
  border: 1px solid rgba(var(--help-hero-ring), 0.18);
}

.ring-inner {
  right: 60px;
  top: 40px;
  width: 220px;
  height: 220px;
  border: 1px solid rgba(var(--help-hero-ring), 0.28);
}

.hero-inner {
  position: relative;
  max-width: 1040px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.eyebrow {
  margin: 0;
  font-size: 0.8125rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--help-hero-accent);
}

.hero-title {
  margin: 0;
  font-size: 3.25rem;
  font-weight: 700;
  line-height: 1.05;
  color: var(--help-hero-heading);
}

.hero-search {
  max-width: 680px;
}

.popular {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  font-size: 0.875rem;
  color: var(--help-hero-muted);
}

.popular-link {
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  padding: 4px 12px;
  border: 1px solid rgba(227, 232, 239, 0.3);
  border-radius: 999px;
  color: var(--help-hero-text);
  text-decoration: none;
}

.popular-link:hover {
  border-color: var(--help-hero-accent);
  background: rgba(var(--help-hero-ring), 0.12);
}

.popular-link:focus-visible {
  outline: 2px solid var(--help-hero-accent);
  outline-offset: 2px;
}

.body {
  position: relative;
  max-width: 1040px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 36px;
}

.notices {
  margin-top: 24px;
  margin-bottom: -12px;
}

/* The highlight cards overlap the bottom of the hero band. */
.highlights {
  margin-top: -32px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 20px;
}

.notices + .highlights {
  margin-top: 0;
}

.card {
  display: flex;
  border-radius: 12px;
  border: 1px solid var(--help-card-border);
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  box-shadow: var(--help-card-shadow);
  text-decoration: none;
  transition: box-shadow 0.15s ease, transform 0.15s ease;
}

.card:hover {
  transform: translateY(-1px);
}

.featured {
  grid-column: span 2;
  gap: 22px;
  align-items: flex-start;
  padding: 28px 28px 24px;
}

.highlights--solo .featured,
.highlights--solo .news {
  grid-column: 1 / -1;
}

.featured-icon {
  flex: none;
  width: 52px;
  height: 52px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
}

.featured-text {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}

.kicker,
.news-label {
  font-size: 0.8125rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.kicker {
  color: rgb(var(--v-theme-primary));
}

.featured-title {
  font-size: 1.625rem;
  line-height: 1.15;
  overflow-wrap: anywhere;
}

.featured-summary,
.news-summary,
.section-count,
.chapter-summary {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.featured-summary {
  font-size: 1rem;
  line-height: 1.5;
}

.news {
  position: relative;
  flex-direction: column;
  gap: 10px;
  padding: 24px;
  min-width: 0;
}

.news-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.news-label {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.news-date {
  font-size: 0.75rem;
  font-weight: 600;
  color: rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.08);
  border-radius: 999px;
  padding: 2px 10px;
  white-space: nowrap;
}

.news-version {
  font-size: 2.125rem;
  font-weight: 700;
  line-height: 1;
  overflow-wrap: anywhere;
}

.news-summary {
  font-size: 0.9375rem;
  line-height: 1.45;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
}

.news-more {
  margin-top: auto;
  font-weight: 600;
  font-size: 0.9375rem;
  color: rgb(var(--v-theme-primary));
}

.news-chevron {
  display: none;
}

.section {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  gap: 32px;
  align-items: start;
}

.section-head {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding-top: 6px;
  min-width: 0;
}

/* Decorative: the chapter numbers already carry the section number. */
.ordinal {
  font-size: 2.75rem;
  font-weight: 700;
  line-height: 1;
  color: rgba(var(--v-theme-primary), 0.3);
}

.section-title {
  margin: 0;
  font-size: 1.5rem;
  line-height: 1.2;
  overflow-wrap: anywhere;
}

.section-count {
  font-size: 0.875rem;
}

.chapters {
  list-style: none;
  margin: 0;
  padding: 0;
  overflow: hidden;
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.65));
}

.chapters li + li {
  border-top: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.5));
}

.chapter {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr) 20px;
  gap: 12px;
  align-items: center;
  min-height: 44px;
  padding: 16px 20px;
  color: rgb(var(--v-theme-on-surface));
  text-decoration: none;
}

.chapter:hover {
  background: rgba(var(--v-theme-primary), 0.05);
}

.chapter:focus-visible {
  outline-offset: -2px;
}

.chapter-number {
  font-size: 0.9375rem;
  color: rgb(var(--v-theme-primary));
}

.chapter-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.chapter-title {
  font-weight: 600;
  font-size: 1.0625rem;
  overflow-wrap: anywhere;
}

.chapter-summary {
  font-size: 0.875rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.chevron {
  color: rgb(var(--v-theme-secondary));
}

@media (max-width: 959.98px) {
  .section {
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
  }

  .section-head {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 10px;
  }

  .ordinal {
    font-size: 1.5rem;
  }
}

/* Phone: one column, a smaller hero, a compact "What's new" row. */
@media (max-width: 599.98px) {
  .hero {
    margin: -16px -16px 0;
    padding: 28px 20px 44px;
  }

  .ring {
    display: none;
  }

  .hero-inner {
    gap: 14px;
  }

  .eyebrow {
    font-size: 0.75rem;
  }

  .hero-title {
    font-size: 2.25rem;
  }

  .body {
    gap: 28px;
  }

  .highlights {
    margin-top: -20px;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
  }

  .featured,
  .news {
    grid-column: auto;
  }

  .featured {
    padding: 18px;
  }

  .featured-icon {
    display: none;
  }

  .featured-text {
    gap: 6px;
  }

  .kicker,
  .news-label {
    font-size: 0.75rem;
  }

  .featured-title {
    font-size: 1.375rem;
  }

  .featured-summary {
    font-size: 0.9375rem;
  }

  .news {
    flex-direction: row;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    min-height: 44px;
  }

  .news-version {
    order: -1;
    font-size: 1.5rem;
  }

  .news-head {
    flex: 1;
    justify-content: flex-start;
  }

  .news-summary,
  .news-more {
    display: none;
  }

  .news-chevron {
    display: inline-flex;
    color: rgb(var(--v-theme-secondary));
  }

  .section-title {
    font-size: 1.25rem;
  }

  .section-count {
    display: none;
  }

  .chapter {
    grid-template-columns: 36px minmax(0, 1fr) 16px;
    padding: 14px 16px;
  }

  .chapter-title {
    font-weight: 500;
    font-size: 1rem;
  }

  .chapter-summary {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .card {
    transition: none;
  }

  .card:hover {
    transform: none;
  }
}
</style>
