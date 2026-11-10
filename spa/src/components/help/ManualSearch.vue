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

watch(query, schedule)

// Snippets come in the request locale, so a language switch searches again.
watch(locale, () => {
  if (searched.value !== '')
    void search(searched.value)
})

onUnmounted(() => clearTimeout(timer))
</script>

<template>
  <div class="manual-search">
    <v-text-field
      v-model="query"
      :label="t('help.search')"
      :prepend-inner-icon="mdiMagnify"
      :loading="loading"
      type="search"
      clearable
      hide-details
      data-test="manual-search"
    />

    <ProblemAlert :error="error" class="mt-4" />

    <v-list v-if="hits.length > 0" class="mt-2 hits" data-test="search-results" :aria-label="t('help.searchResults')">
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
    <!-- Always present, so screen readers announce the outcome when its text changes. -->
    <p role="status" aria-live="polite" class="mt-2 text-body-2 text-medium-emphasis" data-test="search-status">
      <template v-if="searched !== '' && !loading && !error">
        <span v-if="hits.length > 0">{{ t('help.searchCount', hits.length) }}</span>
        <span v-else data-test="search-empty">{{ t('help.searchEmpty') }}</span>
      </template>
    </p>
  </div>
</template>

<style scoped>
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
</style>
