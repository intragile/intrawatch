<script setup lang="ts">
import type { ImportReportData } from '../../api/types'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ImportEntries from './ImportEntries.vue'

const props = defineProps<{ report: ImportReportData }>()

const OUTCOME_ORDER = [
  'created',
  'would_create',
  'existing',
  'skipped',
  'invalid',
  'failed',
]

const { t, te } = useI18n()

const entities = computed(() => Object.keys(props.report.counts))
const outcomes = computed(() => OUTCOME_ORDER.filter(o => entities.value.some(e => (props.report.counts[e]?.[o] ?? 0) > 0)))
const notMigrated = computed(() => Object.entries(props.report.not_migrated).filter(([, n]) => n > 0))
const notes = computed(() => [
  ...props.report.notes,
  ...props.report.history.notes,
])
const hasHistory = computed(() => props.report.history.monitors > 0)

function label(group: 'entity' | 'outcome' | 'notMigrated', value: string): string {
  return te(`import.${group}.${value}`)
    ? t(`import.${group}.${value}`)
    : value
}
</script>

<template>
  <div>
    <h3 class="text-subtitle-1 mb-2">
      {{ t('import.report.counts') }}
    </h3>
    <v-table v-if="outcomes.length > 0" data-test="import-counts" density="compact" class="mb-4">
      <thead>
        <tr>
          <th scope="col">
            {{ t('import.report.entity') }}
          </th>
          <th v-for="outcome in outcomes" :key="outcome" scope="col" class="text-end">
            {{ label('outcome', outcome) }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="entity in entities" :key="entity">
          <th scope="row">
            {{ label('entity', entity) }}
          </th>
          <td v-for="outcome in outcomes" :key="outcome" :data-test="`count-${entity}-${outcome}`" class="text-end">
            {{ report.counts[entity]?.[outcome] ?? 0 }}
          </td>
        </tr>
      </tbody>
    </v-table>

    <div v-if="notMigrated.length > 0" class="mb-4">
      <h3 class="text-subtitle-1">
        {{ t('import.report.notMigrated') }}
      </h3>
      <p class="text-medium-emphasis mb-1">
        {{ t('import.report.notMigratedHint') }}
      </p>
      <v-chip v-for="[key, n] in notMigrated" :key="key" label size="small" class="mr-2">
        {{ label('notMigrated', key) }}: {{ n }}
      </v-chip>
    </div>

    <div v-if="hasHistory" class="mb-4">
      <h3 class="text-subtitle-1">
        {{ t('import.report.history') }}
      </h3>
      <p>
        {{ t('import.report.historySummary', { monitors: report.history.monitors, hours: report.history.hour_rows, days: report.history.day_rows }) }}
      </p>
    </div>

    <div v-if="notes.length > 0" class="mb-4">
      <h3 class="text-subtitle-1">
        {{ t('import.report.notes') }}
      </h3>
      <ul class="pl-4">
        <li v-for="note in notes" :key="note.code + note.message">
          {{ note.message }}
        </li>
      </ul>
    </div>

    <ImportEntries :entries="report.entries" />
  </div>
</template>
