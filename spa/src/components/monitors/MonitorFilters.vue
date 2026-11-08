<script setup lang="ts">
import type { MonitorType } from '../../api/types'
import type { MonitorFilterValues } from './filters'
import { mdiFilterVariant } from '@mdi/js'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
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

const text = ref(props.modelValue.q)

// The secondary filters fold away; they stay open while any of them is set.
const extraSet = computed(() => props.modelValue.type !== null || props.modelValue.tag !== null || props.modelValue.active !== null)
const panelOpen = ref(extraSet.value)
watch(extraSet, (set) => {
  if (set)
    panelOpen.value = true
})
let timer: ReturnType<typeof setTimeout> | null = null

watch(() => props.modelValue.q, (q) => {
  // The applied value is trimmed; keep what the user is still typing (e.g. a trailing space).
  if (q !== text.value.trim())
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
  <form class="filters" role="search" :aria-label="t('monitors.filters.label')" @submit.prevent>
    <div class="search-row">
      <v-text-field
        :model-value="text"
        name="q"
        type="search"
        :label="t('monitors.filters.search')"
        density="compact"
        hide-details
        clearable
        class="search"
        @update:model-value="onSearch"
      />
      <v-btn
        data-test="filter-more"
        variant="text"
        :prepend-icon="mdiFilterVariant"
        :aria-expanded="panelOpen"
        aria-controls="monitor-filter-panel"
        :color="extraSet ? 'primary' : undefined"
        @click="panelOpen = !panelOpen"
      >
        {{ t('monitors.filters.more') }}
      </v-btn>
    </div>
    <div v-show="panelOpen" id="monitor-filter-panel" class="panel">
      <v-select
        data-test="filter-type"
        :model-value="modelValue.type"
        :items="TYPES.map(v => ({ value: v, title: t(`monitors.types.${v}`) }))"
        :label="t('monitors.filters.type')"
        density="compact"
        hide-details
        clearable
        @update:model-value="patch({ type: $event ?? null })"
      />
      <v-select
        data-test="filter-tag"
        :model-value="modelValue.tag"
        :items="tags.map(tag => ({ value: tag.id, title: tag.name }))"
        :label="t('monitors.filters.tag')"
        density="compact"
        hide-details
        clearable
        @update:model-value="patch({ tag: $event ?? null })"
      />
      <v-select
        data-test="filter-active"
        :model-value="modelValue.active"
        :items="[{ value: 'true', title: t('monitors.filters.activeOnly') }, { value: 'false', title: t('monitors.filters.pausedOnly') }]"
        :label="t('monitors.filters.active')"
        density="compact"
        hide-details
        clearable
        @update:model-value="patch({ active: $event ?? null })"
      />
      <v-btn v-if="anyActive" data-test="filter-reset" variant="text" size="small" class="reset" @click="emit('reset')">
        {{ t('monitors.filters.reset') }}
      </v-btn>
    </div>
  </form>
</template>

<style scoped>
.filters {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.search-row {
  display: flex;
  align-items: center;
  gap: 4px;
}

.search {
  flex: 1;
  min-width: 0;
}

.panel {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.reset {
  justify-self: start;
  align-self: center;
}
</style>
