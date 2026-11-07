<script setup lang="ts">
import type { Monitor } from '../../api/types'
import type { Beat } from '../../stores/monitors'
import { mdiChevronDown, mdiChevronRight } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSession } from '../../stores/session'
import { relativeTime } from '../../utils/relativeTime'
import StatusChip from '../common/StatusChip.vue'
import HeartbeatBar from './HeartbeatBar.vue'

const props = defineProps<{
  monitor: Monitor
  beats: Beat[] | undefined
  depth: number
  selectable: boolean
  selected: boolean
  expandable: boolean
  expanded: boolean
  /** Epoch ms the relative time is measured against. */
  now: number
}>()
const emit = defineEmits<{
  select: []
  expand: []
  open: []
}>()

const { t, locale } = useI18n()
const session = useSession()

const since = computed(() => relativeTime(props.monitor.state.since, props.now, locale.value))
const absolute = computed(() => {
  const date = new Date(props.monitor.state.since)
  if (Number.isNaN(date.getTime()))
    return props.monitor.state.since
  try {
    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: 'medium',
      timeStyle: 'medium',
      timeZone: session.current?.timezone ?? 'UTC',
      timeZoneName: 'short',
    }).format(date)
  }
  catch {
    return date.toISOString()
  }
})

function onRowClick(event: MouseEvent): void {
  const target = event.target as HTMLElement | null
  if (target?.closest('a, button, input, label'))
    return
  emit('open')
}
</script>

<template>
  <tr :data-test="`row-${monitor.id}`" class="monitor-row" @click="onRowClick">
    <td v-if="selectable" class="select-cell">
      <input
        type="checkbox"
        :data-test="`select-${monitor.id}`"
        :checked="selected"
        :aria-label="t('monitors.selectRow', { name: monitor.name })"
        @change="emit('select')"
      >
    </td>
    <td><StatusChip :status="monitor.state.status" /></td>
    <td>
      <div class="d-flex align-center" :style="{ paddingInlineStart: `${depth * 28}px` }">
        <v-btn
          v-if="expandable"
          :data-test="`expand-${monitor.id}`"
          :icon="expanded ? mdiChevronDown : mdiChevronRight"
          :aria-label="t(expanded ? 'monitors.collapse' : 'monitors.expand', { name: monitor.name })"
          :aria-expanded="expanded"
          variant="text"
          size="small"
          density="comfortable"
          class="mr-1"
          @click="emit('expand')"
        />
        <router-link :to="{ name: 'ws.monitor', params: { monitor: monitor.id } }" class="monitor-link">
          {{ monitor.name }}
        </router-link>
      </div>
    </td>
    <td>{{ t(`monitors.types.${monitor.type}`) }}</td>
    <td>
      <span v-if="monitor.state.last_latency_ms !== null">{{ t('monitors.latencyMs', { ms: monitor.state.last_latency_ms }) }}</span>
      <span v-else aria-hidden="true">{{ t('monitors.noLatency') }}</span>
    </td>
    <td>
      <time :datetime="monitor.state.since" :title="absolute">{{ since }}</time>
    </td>
    <td>
      <v-chip v-for="tag in monitor.tags" :key="tag" size="x-small" label class="mr-1">
        {{ tag }}
      </v-chip>
    </td>
    <td><HeartbeatBar :beats="beats" /></td>
  </tr>
</template>

<style scoped>
.monitor-row {
  cursor: pointer;
}

.monitor-link {
  color: inherit;
  font-weight: 500;
}

.select-cell {
  width: 2.5rem;
}
</style>
