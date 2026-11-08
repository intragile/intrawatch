<script setup lang="ts">
import type { ImportEntryData } from '../../api/types'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ entries: ImportEntryData[] }>()

const PAGE = 100
const ATTENTION = 'attention'
const ALL = 'all'
const CALM = new Set(['created', 'would_create'])

const { t, te } = useI18n()

const entity = ref<string>(ALL)
const outcome = ref<string>(ATTENTION)
const shown = ref(PAGE)

const entities = computed(() => [
  ...new Set(props.entries.map(e => e.entity)),
])
const outcomes = computed(() => [
  ...new Set(props.entries.map(e => e.outcome)),
])

const entityItems = computed(() => [
  {
    value: ALL,
    title: t('import.entries.allEntities'),
  },
  ...entities.value.map(value => ({
    value,
    title: label('entity', value),
  })),
])
const outcomeItems = computed(() => [
  {
    value: ATTENTION,
    title: t('import.entries.attention'),
  },
  {
    value: ALL,
    title: t('import.entries.all'),
  },
  ...outcomes.value.map(value => ({
    value,
    title: label('outcome', value),
  })),
])

const filtered = computed(() => props.entries.filter((e) => {
  if (entity.value !== ALL && e.entity !== entity.value)
    return false
  if (outcome.value === ATTENTION)
    return !CALM.has(e.outcome)
  return outcome.value === ALL || e.outcome === outcome.value
}))
const visible = computed(() => filtered.value.slice(0, shown.value))

watch([entity, outcome], () => {
  shown.value = PAGE
})

function label(group: 'entity' | 'outcome', value: string): string {
  return te(`import.${group}.${value}`)
    ? t(`import.${group}.${value}`)
    : value
}

function errorLines(entry: ImportEntryData): string[] {
  return Object.entries(entry.errors ?? {}).flatMap(([field, messages]) => messages.map(m => `${field}: ${m}`))
}
</script>

<template>
  <section aria-labelledby="import-entries-title" data-test="import-entries">
    <h3 id="import-entries-title" class="text-subtitle-1 mb-2">
      {{ t('import.entries.title') }}
    </h3>
    <div class="d-flex flex-wrap ga-4">
      <v-select
        v-model="entity"
        data-test="filter-entity"
        :items="entityItems"
        :label="t('import.entries.entity')"
        density="compact"
        max-width="260"
      />
      <v-select
        v-model="outcome"
        data-test="filter-outcome"
        :items="outcomeItems"
        :label="t('import.entries.outcome')"
        density="compact"
        max-width="260"
      />
    </div>
    <p v-if="filtered.length === 0" class="text-medium-emphasis">
      {{ t('import.entries.empty') }}
    </p>
    <v-table v-else density="compact">
      <thead>
        <tr>
          <th scope="col">
            {{ t('import.entries.entity') }}
          </th>
          <th scope="col">
            {{ t('import.entries.name') }}
          </th>
          <th scope="col">
            {{ t('import.entries.outcome') }}
          </th>
          <th scope="col">
            {{ t('import.entries.details') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="entry in visible" :key="`${entry.entity}-${entry.source_id}`">
          <td>{{ label('entity', entry.entity) }}</td>
          <td>{{ entry.name }}</td>
          <td>{{ label('outcome', entry.outcome) }}</td>
          <td>
            <div v-for="(note, index) in entry.notes" :key="`${index}-${note.code}`">
              {{ note.message }}
            </div>
            <div v-for="line in errorLines(entry)" :key="line" class="text-error">
              {{ line }}
            </div>
          </td>
        </tr>
      </tbody>
    </v-table>
    <v-btn
      v-if="filtered.length > visible.length"
      data-test="entries-more"
      variant="tonal"
      class="mt-3"
      @click="shown += PAGE"
    >
      {{ t('import.entries.showMore', { n: filtered.length - visible.length }) }}
    </v-btn>
  </section>
</template>
