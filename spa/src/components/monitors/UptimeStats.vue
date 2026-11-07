<script setup lang="ts">
import type { MonitorRollup } from '../../api/types'
import type { RangeKey } from './chartData'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { availability, RANGES } from './chartData'

const props = defineProps<{
  /** Rollups per range; null while a range has not loaded. */
  ranges: Record<RangeKey, MonitorRollup[] | null>
}>()

const { t, locale } = useI18n()

const cells = computed(() => {
  const format = new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 2 })
  return RANGES.map((range) => {
    const rollups = props.ranges[range.key]
    const value = rollups === null
      ? null
      : availability(rollups)
    return {
      key: range.key,
      label: t(`monitorDetail.ranges.${range.key}`),
      text: value === null
        ? '—'
        : format.format(value),
      known: value !== null,
    }
  })
})
</script>

<template>
  <section :aria-label="t('monitorDetail.uptime.title')">
    <h2 class="text-h6 mb-2">
      {{ t('monitorDetail.uptime.title') }}
    </h2>
    <v-row dense>
      <v-col v-for="cell in cells" :key="cell.key" cols="6" sm="3">
        <v-card variant="tonal">
          <v-card-text :data-test="`uptime-${cell.key}`">
            <div class="text-caption">
              {{ cell.label }}
            </div>
            <div class="text-h6">
              <span v-if="!cell.known" :aria-label="t('monitorDetail.uptime.none')">{{ cell.text }}</span>
              <span v-else>{{ cell.text }}</span>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </section>
</template>
