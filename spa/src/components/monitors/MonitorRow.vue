<script setup lang="ts">
import type { Monitor } from '../../api/types'
import type { Beat } from '../../stores/monitors'
import { mdiChevronDown, mdiChevronRight } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSession } from '../../stores/session'
import { relativeTime } from '../../utils/relativeTime'
import StatusMark from '../common/StatusMark.vue'
import HeartbeatBar from './HeartbeatBar.vue'
import { monitorTarget } from './target'

const props = defineProps<{
  monitor: Monitor
  beats: Beat[] | undefined
  depth: number
  selectable: boolean
  selected: boolean
  expandable: boolean
  expanded: boolean
  /** The monitor open in the detail pane. */
  current: boolean
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

const status = computed(() => props.monitor.state.status)
const target = computed(() => monitorTarget(props.monitor))
const latency = computed(() => props.monitor.state.last_latency_ms)
// Latency only means something while checks succeed; otherwise the status word takes its place.
const showLatency = computed(() => latency.value !== null && (status.value === 'up' || status.value === 'degraded'))

const STATUS_TEXT: Record<Monitor['state']['status'], string> = {
  up: 'text-success',
  down: 'text-error',
  degraded: 'text-warning',
  maintenance: 'text-maintenance',
  pending: 'text-idle',
  paused: 'text-idle',
}

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
  <li
    :data-test="`row-${monitor.id}`"
    class="monitor-row"
    :class="{ 'is-current': current, 'is-child': depth > 0, 'is-selected': selected, 'is-selectable': selectable }"
    @click="onRowClick"
  >
    <input
      v-if="selectable"
      type="checkbox"
      class="select"
      :data-test="`select-${monitor.id}`"
      :checked="selected"
      :aria-label="t('monitors.selectRow', { name: monitor.name })"
      @change="emit('select')"
    >
    <span class="lead">
      <StatusMark :status="status" />
    </span>
    <span class="main">
      <span class="title">
        <v-btn
          v-if="expandable"
          :data-test="`expand-${monitor.id}`"
          :icon="expanded ? mdiChevronDown : mdiChevronRight"
          :aria-label="t(expanded ? 'monitors.collapse' : 'monitors.expand', { name: monitor.name })"
          :aria-expanded="expanded"
          variant="text"
          size="x-small"
          density="compact"
          class="expand"
          @click="emit('expand')"
        />
        <router-link
          :to="{ name: 'ws.monitor', params: { monitor: monitor.id } }"
          class="name"
          :aria-current="current ? 'page' : undefined"
        >
          {{ monitor.name }}
        </router-link>
      </span>
      <span class="sub">
        <span class="d-sr-only">{{ t(`status.${status}`) }}, </span>
        <span>{{ t(`monitors.types.${monitor.type}`) }}</span>
        <span v-if="target" class="target">{{ target }}</span>
      </span>
    </span>
    <span class="side">
      <span v-if="showLatency" class="value">{{ t('monitors.latencyMs', { ms: latency }) }}</span>
      <span v-else class="value" :class="STATUS_TEXT[status]">{{ t(`status.${status}`) }}</span>
      <time class="since" :datetime="monitor.state.since" :title="absolute">{{ since }}</time>
    </span>
    <span class="strip">
      <HeartbeatBar :beats="beats" :slots="40" :group="monitor.type === 'group'" />
    </span>
  </li>
</template>

<style scoped>
.monitor-row {
  display: grid;
  grid-template-columns: 14px minmax(0, 1fr) auto;
  grid-template-areas:
    'lead main side'
    '.    strip strip';
  column-gap: 10px;
  row-gap: 8px;
  align-items: center;
  padding: 11px 16px 12px;
  border-bottom: 1px solid var(--iw-line);
  cursor: pointer;
  list-style: none;
}

.monitor-row.is-selectable {
  grid-template-columns: 16px 14px minmax(0, 1fr) auto;
  grid-template-areas:
    'select lead main side'
    '.      .    strip strip';
}

.monitor-row:hover {
  background: rgba(var(--v-theme-on-surface), 0.035);
}

.monitor-row.is-current {
  background: var(--iw-selected);
  box-shadow: inset 3px 0 0 rgb(var(--v-theme-primary));
}

.monitor-row.is-child {
  padding-inline-start: 40px;
}

.select {
  grid-area: select;
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: rgb(var(--v-theme-primary));
}

.lead {
  grid-area: lead;
  display: grid;
  place-items: center;
}

.title {
  display: flex;
  align-items: center;
  gap: 2px;
  min-width: 0;
}

.expand {
  margin: -4px 0 -4px -6px;
}

.main {
  grid-area: main;
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.25;
}

.name {
  color: inherit;
  font-weight: 600;
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.name:hover {
  text-decoration: underline;
}

.sub {
  display: flex;
  gap: 8px;
  min-width: 0;
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  white-space: nowrap;
}

.target {
  overflow: hidden;
  text-overflow: ellipsis;
}

.side {
  grid-area: side;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  line-height: 1.25;
}

.value {
  font-size: 0.875rem;
  font-weight: 600;
  white-space: nowrap;
}

.since {
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  white-space: nowrap;
}

.strip {
  grid-area: strip;
  min-width: 0;
}
</style>
