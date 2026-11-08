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
  <section class="uptime" aria-labelledby="uptime-title">
    <h2 id="uptime-title" class="text-subtitle-1">
      {{ t('monitorDetail.uptime.title') }}
    </h2>
    <dl class="cells">
      <div v-for="cell in cells" :key="cell.key" class="cell" :data-test="`uptime-${cell.key}`">
        <dt class="label">
          {{ cell.label }}
        </dt>
        <dd class="value iw-display">
          <span v-if="!cell.known" :aria-label="t('monitorDetail.uptime.none')">{{ cell.text }}</span>
          <span v-else>{{ cell.text }}</span>
        </dd>
      </div>
    </dl>
  </section>
</template>

<style scoped>
.uptime {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.cells {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  margin: 0;
  border-top: 1px solid var(--iw-line);
  border-bottom: 1px solid var(--iw-line);
}

.cell {
  padding: 12px 16px 12px 0;
}

.cell + .cell {
  padding-inline-start: 16px;
  border-inline-start: 1px solid var(--iw-line);
}

.label {
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.value {
  margin: 2px 0 0;
  font-size: 1.75rem;
  line-height: 1.1;
}

@media (max-width: 599.98px) {
  .cells {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .cell:nth-child(3) {
    padding-inline-start: 0;
    border-inline-start: 0;
  }
}
</style>
