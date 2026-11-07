<script setup lang="ts">
import type { MonitorStatus, MonitorType } from '../../api/types'
import type { MonitorFilterValues } from './filters'
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { SEARCH_DEBOUNCE_MS } from './filters'

const props = defineProps<{
  modelValue: MonitorFilterValues
  tags: Array<{ id: number, name: string }>
  anyActive: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [MonitorFilterValues], 'reset': [] }>()

const { t } = useI18n()

const TYPES: MonitorType[] = [
  'http',
  'tcp',
  'ping',
  'dns',
  'tls',
  'domain',
  'push',
  'cron',
  'group',
]
const STATUSES: MonitorStatus[] = [
  'up',
  'down',
  'degraded',
  'maintenance',
  'pending',
  'paused',
]

const text = ref(props.modelValue.q)
let timer: ReturnType<typeof setTimeout> | null = null

watch(() => props.modelValue.q, (q) => {
  if (q !== text.value)
    text.value = q
})

function patch(change: Partial<MonitorFilterValues>): void {
  emit('update:modelValue', {
    ...props.modelValue,
    ...change,
  })
}

function onSearch(value: string | null): void {
  text.value = value ?? ''
  if (timer)
    clearTimeout(timer)
  timer = setTimeout(() => {
    timer = null
    patch({
      q: text.value.trim(),
    })
  }, SEARCH_DEBOUNCE_MS)
}

onBeforeUnmount(() => {
  if (timer)
    clearTimeout(timer)
})
</script>

<template>
  <form class="d-flex flex-wrap align-center ga-3 mb-2" role="search" :aria-label="t('monitors.filters.label')" @submit.prevent>
    <v-text-field
      :model-value="text"
      name="q"
      type="search"
      :label="t('monitors.filters.search')"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="filter-search"
      @update:model-value="onSearch"
    />
    <v-select
      data-test="filter-type"
      :model-value="modelValue.type"
      :items="TYPES.map(v => ({ value: v, title: t(`monitors.types.${v}`) }))"
      :label="t('monitors.filters.type')"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="filter-select"
      @update:model-value="patch({ type: $event ?? null })"
    />
    <v-select
      data-test="filter-status"
      :model-value="modelValue.status"
      :items="STATUSES.map(v => ({ value: v, title: t(`status.${v}`) }))"
      :label="t('monitors.filters.status')"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="filter-select"
      @update:model-value="patch({ status: $event ?? null })"
    />
    <v-select
      data-test="filter-tag"
      :model-value="modelValue.tag"
      :items="tags.map(tag => ({ value: tag.id, title: tag.name }))"
      :label="t('monitors.filters.tag')"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="filter-select"
      @update:model-value="patch({ tag: $event ?? null })"
    />
    <v-select
      data-test="filter-active"
      :model-value="modelValue.active"
      :items="[{ value: 'true', title: t('monitors.filters.activeOnly') }, { value: 'false', title: t('monitors.filters.pausedOnly') }]"
      :label="t('monitors.filters.active')"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="filter-select"
      @update:model-value="patch({ active: $event ?? null })"
    />
    <v-btn v-if="anyActive" data-test="filter-reset" variant="text" @click="emit('reset')">
      {{ t('monitors.filters.reset') }}
    </v-btn>
  </form>
</template>

<style scoped>
.filter-search {
  min-width: 14rem;
  max-width: 20rem;
}

.filter-select {
  min-width: 10rem;
  max-width: 14rem;
}
</style>
