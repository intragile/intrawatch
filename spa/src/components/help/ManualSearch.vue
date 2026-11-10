<script setup lang="ts">
import type { ApiError } from '../../api'
import type { paths } from '../../api/schema'
import { mdiMagnify } from '@mdi/js'
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../api'
import { useApiFailure } from '../../composables/useApiFailure'
import ProblemAlert from '../common/ProblemAlert.vue'
import { chapterLocation, highlight } from './manual'

type Hit = paths['/manual/search']['get']['responses'][200]['content']['application/json']['data'][number]

const props = withDefaults(defineProps<{
  /** The large field of the help index hero band, with a search button. */
  hero?: boolean
  /** A query to search at once (e.g. handed over from the compact search of a chapter page). */
  initialQuery?: string
}>(), {
  hero: false,
  initialQuery: '',
})

const DEBOUNCE_MS = 300

const { t, locale } = useI18n()
const failure = useApiFailure()

// Vuetify's clear button sets null.
const query = ref<string | null>('')
const hits = ref<Hit[]>([])
/** The query the shown hits belong to (marks and the empty state follow it, not the input). */
const searched = ref('')
const loading = ref(false)
const error = ref<ApiError | null>(null)

let timer: ReturnType<typeof setTimeout> | undefined
let sequence = 0

async function search(q: string): Promise<void> {
  const mine = ++sequence
  loading.value = true
  error.value = null
  try {
    const { data } = await api.GET('/manual/search', {
      params: {
        query: { q },
      },
    })
    if (mine === sequence && data) {
      hits.value = data.data
      searched.value = q
    }
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

function schedule(): void {
  clearTimeout(timer)
  const q = (query.value ?? '').trim()
  if (q === '') {
    // Clearing the field drops any answer still in flight.
    sequence++
    hits.value = []
    searched.value = ''
    error.value = null
    loading.value = false
    return
  }
  timer = setTimeout(() => void search(q.slice(0, 200)), DEBOUNCE_MS)
}

/** Enter or the search button searches at once instead of waiting for the debounce. */
function submit(): void {
  clearTimeout(timer)
  const q = (query.value ?? '').trim()
  if (q !== '')
    void search(q.slice(0, 200))
}

// Set before the watcher exists, so the handed-over query is searched once, without the debounce.
if (props.initialQuery.trim() !== '') {
  query.value = props.initialQuery
  void search(props.initialQuery.trim().slice(0, 200))
}

watch(query, schedule)

// Snippets come in the request locale, so a language switch searches again.
watch(locale, () => {
  if (searched.value !== '')
    void search(searched.value)
})

onUnmounted(() => clearTimeout(timer))
</script>

<template>
  <div class="manual-search" :class="{ 'manual-search--hero': hero }">
    <form role="search" class="search-form" @submit.prevent="submit">
      <v-text-field
        v-model="query"
        :label="hero ? undefined : t('help.search')"
        :aria-label="hero ? t('help.search') : undefined"
        :placeholder="hero ? t('help.searchPlaceholder') : undefined"
        :prepend-inner-icon="mdiMagnify"
        :loading="loading"
        :variant="hero ? 'solo' : undefined"
        :rounded="hero ? 'lg' : undefined"
        type="search"
        clearable
        hide-details
        class="search-field"
        data-test="manual-search"
      >
        <template v-if="hero" #append-inner>
          <v-btn type="submit" color="primary" variant="flat" class="search-submit" data-test="search-submit">
            {{ t('help.searchSubmit') }}
          </v-btn>
        </template>
      </v-text-field>
    </form>

    <div v-if="error || hits.length > 0" class="results">
      <ProblemAlert :error="error" />

      <v-list v-if="hits.length > 0" class="hits" data-test="search-results" :aria-label="t('help.searchResults')">
        <v-list-item
          v-for="hit in hits"
          :key="hit.slug"
          :to="chapterLocation(hit.slug)"
          class="hit"
          data-test="search-hit"
        >
          <v-list-item-title class="hit-title">
            {{ hit.title }}
          </v-list-item-title>
          <!-- eslint-disable vue/no-v-html -- highlight() escapes the plain-text snippet before adding <mark> -->
          <div class="hit-snippet text-body-2 text-medium-emphasis" v-html="highlight(hit.snippet, searched)" />
          <!-- eslint-enable vue/no-v-html -->
        </v-list-item>
      </v-list>
    </div>
    <!-- Always present, so screen readers announce the outcome when its text changes. -->
    <p role="status" aria-live="polite" class="search-status text-body-2" data-test="search-status">
      <template v-if="searched !== '' && !loading && !error">
        <span v-if="hits.length > 0">{{ t('help.searchCount', hits.length) }}</span>
        <span v-else data-test="search-empty">{{ t('help.searchEmpty') }}</span>
      </template>
    </p>
  </div>
</template>

<style scoped>
.results {
  margin-top: 8px;
}

.hits {
  background: transparent;
}

.hit-title {
  white-space: normal;
  font-weight: 500;
}

.hit-snippet {
  overflow-wrap: anywhere;
}

.hit-snippet :deep(mark) {
  background: rgba(var(--v-theme-warning), 0.25);
  color: inherit;
  border-radius: 2px;
}

.search-status {
  margin: 8px 0 0;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.search-status:empty {
  margin: 0;
}

/* Hero variant: a large field on the dark band; the results float on a surface panel below it. */
.manual-search--hero .search-field :deep(.v-field) {
  box-shadow: 0 0 0 1px var(--help-field-ring, transparent), 0 10px 30px rgba(0, 0, 0, 0.25);
  font-size: 1.0625rem;
  padding-inline-end: 6px;
}

.manual-search--hero .search-field :deep(.v-field__input) {
  min-height: 56px;
}

.search-submit {
  min-height: 44px;
  text-transform: none;
  letter-spacing: normal;
  font-weight: 600;
}

.manual-search--hero .results {
  margin-top: 12px;
  padding: 8px;
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
}

.manual-search--hero .search-status {
  color: var(--help-hero-muted, inherit);
}

@media (max-width: 599.98px) {
  .search-submit {
    display: none;
  }
}
</style>
