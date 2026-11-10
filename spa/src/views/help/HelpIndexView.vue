<script setup lang="ts">
import type { ApiError } from '../../api'
import type { paths } from '../../api/schema'
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { chapterLocation } from '../../components/help/manual'
import { manualIcon } from '../../components/help/manualIcons'
import ManualSearch from '../../components/help/ManualSearch.vue'
import { useApiFailure } from '../../composables/useApiFailure'

type Index = paths['/manual']['get']['responses'][200]['content']['application/json']

const { t, locale } = useI18n()
const failure = useApiFailure()

const manual = ref<Index | null>(null)
const loading = ref(false)
const error = ref<ApiError | null>(null)

async function load(): Promise<void> {
  if (loading.value)
    return
  loading.value = true
  error.value = null
  try {
    const { data } = await api.GET('/manual')
    if (data)
      manual.value = data
  }
  catch (e) {
    error.value = failure(e)
  }
  finally {
    loading.value = false
  }
}

onMounted(load)

// Section and chapter titles are served in the request locale, so a language switch reloads them.
watch(locale, () => {
  manual.value = null
  void load()
})
</script>

<template>
  <div class="help">
    <h1 class="text-h5 mb-4">
      {{ t('help.title') }}
    </h1>

    <ManualSearch class="mb-6" />

    <ProblemAlert :error="error" class="mb-4" />
    <div v-if="error && !manual" class="mb-4">
      <v-btn variant="tonal" :loading="loading" data-test="retry" @click="load">
        {{ t('common.retry') }}
      </v-btn>
    </div>

    <template v-if="manual">
      <v-alert
        v-if="manual.fallback && manual.locale !== locale"
        type="info"
        variant="tonal"
        density="compact"
        class="mb-4"
        data-test="fallback"
      >
        {{ t('help.indexFallbackNotice') }}
      </v-alert>

      <v-card
        v-for="section in manual.data"
        :key="section.id"
        class="mb-4"
        data-test="section"
      >
        <v-card-title tag="h2" class="text-h6 d-flex align-center ga-2 section-title">
          <v-icon v-if="manualIcon(section.icon)" :icon="manualIcon(section.icon) ?? undefined" size="small" />
          <span>{{ section.title }}</span>
        </v-card-title>
        <v-list density="compact" class="chapters">
          <v-list-item
            v-for="chapter in section.chapters"
            :key="chapter.slug"
            :to="chapterLocation(chapter.slug)"
            class="chapter"
            data-test="chapter"
          >
            <v-list-item-title class="chapter-title">
              <span class="chapter-number" data-test="chapter-number">{{ chapter.number }}</span>
              {{ chapter.title }}
            </v-list-item-title>
            <div class="chapter-summary text-body-2 text-medium-emphasis">
              {{ chapter.summary }}
            </div>
          </v-list-item>
        </v-list>
      </v-card>
    </template>
  </div>
</template>

<style scoped>
.help {
  max-width: 880px;
}

.section-title {
  overflow-wrap: anywhere;
  white-space: normal;
}

.chapters {
  background: transparent;
}

.chapter-title {
  white-space: normal;
  font-weight: 500;
}

.chapter-number {
  display: inline-block;
  min-width: 2.25em;
  color: rgb(var(--v-theme-primary));
  font-variant-numeric: tabular-nums;
}

.chapter-summary {
  overflow-wrap: anywhere;
  padding-inline-start: 2.25em;
}
</style>
