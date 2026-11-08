<script setup lang="ts">
import type { MonitorStatus } from '../../api/types'
import { computed, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusChip from '../../components/common/StatusChip.vue'
import StatusMark from '../../components/common/StatusMark.vue'
import { relativeTime } from '../../utils/relativeTime'
import { MONITOR_OVERVIEW } from './overview'

const { t, locale } = useI18n()
const data = inject(MONITOR_OVERVIEW, null)

const ORDER: MonitorStatus[] = [
  'down',
  'degraded',
  'maintenance',
  'up',
  'paused',
  'pending',
]
const tiles = computed(() => ORDER
  .map(status => ({ status, count: data?.counts.value.get(status) ?? 0 }))
  .filter(tile => tile.count > 0 || tile.status === 'down' || tile.status === 'up'))

// Outages first, then slow monitors, then maintenance; each list keeps the list's order.
const RANK: Partial<Record<MonitorStatus, number>> = { down: 0, degraded: 1, maintenance: 2 }
const attention = computed(() => (data?.monitors.value ?? [])
  .filter(m => RANK[m.state.status] !== undefined)
  .sort((a, b) => (RANK[a.state.status] ?? 9) - (RANK[b.state.status] ?? 9)))
</script>

<template>
  <div class="overview">
    <h2 class="text-h5">
      {{ t('monitors.overview.title') }}
    </h2>

    <dl class="tiles">
      <div v-for="tile in tiles" :key="tile.status" class="tile" :data-test="`overview-${tile.status}`">
        <dt class="tile-label">
          <StatusMark :status="tile.status" />
          {{ t(`status.${tile.status}`) }}
        </dt>
        <dd class="tile-value iw-display">
          {{ tile.count }}
        </dd>
      </div>
    </dl>
    <p class="note">
      {{ t('monitors.overview.loadedNote') }}
    </p>

    <section v-if="attention.length > 0" aria-labelledby="attention-title" class="attention">
      <h3 id="attention-title" class="text-subtitle-1">
        {{ t('monitors.overview.attention') }}
      </h3>
      <ul>
        <li v-for="m in attention" :key="m.id">
          <router-link :to="{ name: 'ws.monitor', params: { monitor: m.id } }" class="name">
            {{ m.name }}
          </router-link>
          <span class="msg">{{ m.state.last_message }}</span>
          <StatusChip :status="m.state.status" size="x-small" />
          <time class="when" :datetime="m.state.since">{{ relativeTime(m.state.since, data?.now.value ?? Date.now(), locale) }}</time>
        </li>
      </ul>
    </section>
    <p v-else class="all-good">
      {{ t('monitors.overview.allGood') }}
    </p>

    <p class="hint">
      {{ t('monitors.overview.hint') }}
    </p>
  </div>
</template>

<style scoped>
.overview {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 880px;
}

.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  margin: 0;
  border-top: 1px solid var(--iw-line);
  border-bottom: 1px solid var(--iw-line);
}

.tile {
  padding: 14px 16px 14px 0;
}

.tile-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.tile-value {
  margin: 2px 0 0;
  font-size: 2.5rem;
  line-height: 1.05;
}

.note,
.hint {
  margin: 0;
  font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.attention ul {
  margin: 8px 0 0;
  padding: 0;
  list-style: none;
}

.attention li {
  display: grid;
  grid-template-columns: minmax(0, 14rem) minmax(0, 1fr) auto auto;
  align-items: baseline;
  gap: 16px;
  padding: 10px 0;
  border-bottom: 1px solid var(--iw-line);
}

.name {
  color: inherit;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.msg {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.when {
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  white-space: nowrap;
}

.all-good {
  margin: 0;
}
</style>
