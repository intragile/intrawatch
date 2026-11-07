<script setup lang="ts">
import type { Monitor, MonitorStatus } from '../../api/types'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusChip from '../common/StatusChip.vue'

const props = defineProps<{
  monitors: Monitor[]
}>()

const { t } = useI18n()

const ORDER: MonitorStatus[] = [
  'down',
  'degraded',
  'maintenance',
  'pending',
  'paused',
  'up',
]

const counts = computed(() => {
  const result = new Map<MonitorStatus, number>()
  for (const m of props.monitors)
    result.set(m.state.status, (result.get(m.state.status) ?? 0) + 1)
  return ORDER.filter(s => result.has(s)).map(status => ({ status, count: result.get(status) ?? 0 }))
})
</script>

<template>
  <section class="d-flex flex-wrap align-center ga-2 mb-4" :aria-label="t('monitors.summary.label')" data-test="summary">
    <div v-for="entry in counts" :key="entry.status" class="d-flex align-center ga-1" :data-test="`summary-${entry.status}`">
      <StatusChip :status="entry.status" />
      <span>{{ t('monitors.summary.ofLoaded', { count: entry.count, total: monitors.length }) }}</span>
    </div>
  </section>
</template>
