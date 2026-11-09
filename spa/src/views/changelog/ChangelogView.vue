<script setup lang="ts">
import type { ApiError } from '../../api'
import type { components } from '../../api/schema'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useSession } from '../../stores/session'

type Release = components['schemas']['ReleaseResource']

const { t, locale } = useI18n()
const session = useSession()
const failure = useApiFailure()

const releases = ref<Release[]>([])
const page = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loaded = ref(false)
const error = ref<ApiError | null>(null)

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

const CALENDAR_DATE = /^\d{4}-\d{2}-\d{2}$/

/** A bare release date is a calendar day and is not shifted between zones; a timestamp is shown in the workspace zone. */
function formatDate(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime()))
    return value
  try {
    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: 'long',
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
</script>

<template>
  <h1 class="text-h5 mb-4">
    {{ t('changelog.title') }}
  </h1>

  <ProblemAlert :error="error" class="mb-4" />

  <v-card
    v-for="release in releases"
    :key="release.version"
    class="mb-4"
    max-width="720"
    data-test="release"
  >
    <v-card-title tag="h2" class="text-h6 d-flex flex-wrap align-center ga-2">
      <component
        :is="release.url ? 'a' : 'span'"
        :href="release.url ?? undefined"
        :target="release.url ? '_blank' : undefined"
        :rel="release.url ? 'noopener noreferrer' : undefined"
        class="version"
        :class="{ yanked: release.yanked }"
      >
        {{ release.version }}
      </component>
      <span class="text-body-2 text-medium-emphasis">
        <template v-if="release.unreleased">{{ t('changelog.unreleased') }}</template>
        <time v-else-if="release.date" :datetime="release.date">{{ formatDate(release.date) }}</time>
      </span>
      <v-chip
        v-if="release.yanked"
        color="error"
        size="small"
        variant="tonal"
        data-test="yanked"
        :title="t('changelog.yankedHint')"
      >
        {{ t('changelog.yanked') }}
      </v-chip>
    </v-card-title>
    <v-card-text>
      <v-alert v-if="showFallback(release)" type="info" variant="tonal" density="compact" class="mb-4" data-test="fallback">
        {{ t('changelog.fallbackNotice') }}
      </v-alert>
      <!-- eslint-disable vue/no-v-html -- sanitized server-side: raw HTML is stripped and unsafe links removed -->
      <div v-if="release.summary_html" class="prose mb-4" v-html="release.summary_html" />
      <section v-for="group in release.groups" :key="group.name" class="mb-4">
        <h3 class="text-subtitle-1 font-weight-bold">
          {{ group.name }}
        </h3>
        <div v-if="group.summary_html" class="prose" v-html="group.summary_html" />
        <ul class="items">
          <li v-for="(item, i) in group.items" :key="i" class="prose" v-html="item.html" />
        </ul>
      </section>
      <!-- eslint-enable vue/no-v-html -->
    </v-card-text>
  </v-card>

  <p v-if="loaded && releases.length === 0" class="text-medium-emphasis">
    {{ t('changelog.empty') }}
  </p>
  <div v-if="page < lastPage && releases.length > 0">
    <v-btn variant="tonal" :loading="loading" data-test="load-more" @click="load">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>
</template>

<style scoped>
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

.items {
  padding-left: 1.25rem;
}

.prose {
  overflow-wrap: anywhere;
}

.prose :deep(pre) {
  overflow-x: auto;
}
</style>
