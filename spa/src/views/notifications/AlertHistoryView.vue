<script setup lang="ts">
import type { AlertKind } from '../../api/types'
import { mdiBellOff } from '@mdi/js'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, useCursorList } from '../../api'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import StatusChip from '../../components/common/StatusChip.vue'
import DeliveryStatusChip from '../../components/notifications/DeliveryStatusChip.vue'
import { useWorkspaceLive } from '../../live/useWorkspaceLive'
import { bodyOf } from '../../utils/cursorPage'

const KINDS: AlertKind[] = [
  'down',
  'up',
  'degraded',
  'still_down',
  'flapping',
  'stable',
  'expiring',
  'renewed',
  'changed',
  'test',
]
/** Monitors offered by the filter per search. */
const MONITOR_OPTIONS = 20

const { t } = useI18n()
const route = useRoute()
const live = useWorkspaceLive()

const slug = computed(() => String(route.params.workspace))
const kind = ref<AlertKind | null>(null)
const monitorId = ref<number | null>(null)
const since = ref('')

/** The API accepts ISO 8601 without fractional seconds only. */
function sinceParam(): string | undefined {
  if (since.value === '')
    return undefined
  const date = new Date(since.value)
  return Number.isNaN(date.getTime())
    ? undefined
    : date.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

function fetchPage(cursor: string | null) {
  return bodyOf(api.GET('/workspaces/{workspace}/alerts', {
    params: {
      path: { workspace: slug.value },
      query: {
        cursor: cursor ?? undefined,
        kind: kind.value ?? undefined,
        monitor_id: monitorId.value ?? undefined,
        since: sinceParam(),
      },
    },
  }))
}

const list = useCursorList(fetchPage)

/** Fetches the first page again and merges it by id, keeping the older pages already loaded. */
async function refreshFirstPage(): Promise<void> {
  const token = `${slug.value}|${kind.value}|${monitorId.value}|${since.value}`
  try {
    const fresh = await fetchPage(null)
    if (token !== `${slug.value}|${kind.value}|${monitorId.value}|${since.value}`)
      return
    const ids = new Set(fresh.data.map(row => row.id))
    list.items.value = [
      ...fresh.data,
      ...list.items.value.filter(row => !ids.has(row.id)),
    ]
  }
  catch {
    // The next event or a manual reload recovers; the table keeps what it shows.
  }
}

watch([slug, kind, monitorId, since], () => {
  void list.reload()
})
void list.loadMore()

// A new alert goes to the top, so the first page is fetched again instead of patching rows.
const stops = [
  live.on('alert', () => {
    void refreshFirstPage()
  }),
  live.onResync(() => {
    void list.reloadLoaded()
  }),
]
onBeforeUnmount(() => {
  for (const stop of stops)
    stop()
})

const monitorOptions = ref<Array<{ id: number, name: string }>>([])
const monitorSearch = ref('')
let searchSeq = 0
let timer: ReturnType<typeof setTimeout> | undefined

async function searchMonitors(q: string): Promise<void> {
  const mine = ++searchSeq
  try {
    const result = await bodyOf(api.GET('/workspaces/{workspace}/monitors', {
      params: {
        path: { workspace: slug.value },
        query: {
          q: q.trim() === ''
            ? undefined
            : q.trim(),
          limit: MONITOR_OPTIONS,
        },
      },
    }))
    if (mine === searchSeq)
      monitorOptions.value = result.data.map(m => ({ id: m.id, name: m.name }))
  }
  catch {
    // The filter keeps what it has.
  }
}

watch(monitorSearch, (q) => {
  clearTimeout(timer)
  timer = setTimeout(() => void searchMonitors(q), 250)
})
onBeforeUnmount(() => clearTimeout(timer))

function duration(seconds: number | null): string {
  if (seconds === null)
    return '-'
  if (seconds < 60)
    return t('alerts.seconds', { n: seconds })
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60)
    return t('alerts.minutes', { n: minutes, s: seconds % 60 })
  return t('alerts.hours', {
    n: Math.floor(minutes / 60),
    m: minutes % 60,
  })
}
</script>

<template>
  <h1 class="text-h5 mb-4">
    {{ t('alerts.title') }}
  </h1>

  <div class="d-flex flex-wrap ga-4 mb-2">
    <v-select
      v-model="kind"
      data-test="alert-kind"
      name="alert_kind"
      :items="KINDS.map(v => ({ value: v, title: t(`alerts.kinds.${v}`) }))"
      :label="t('alerts.filters.kind')"
      clearable
      density="compact"
      style="max-width: 220px"
    />
    <v-autocomplete
      v-model="monitorId"
      v-model:search="monitorSearch"
      data-test="alert-monitor"
      name="alert_monitor"
      :items="monitorOptions"
      item-title="name"
      item-value="id"
      :label="t('alerts.filters.monitor')"
      clearable
      no-filter
      density="compact"
      style="max-width: 260px"
      @focus="searchMonitors(monitorSearch)"
    />
    <v-text-field
      v-model="since"
      name="alert_since"
      type="datetime-local"
      :label="t('alerts.filters.since')"
      density="compact"
      style="max-width: 240px"
    />
  </div>

  <ProblemAlert :error="list.error.value" />

  <v-table v-if="list.items.value.length > 0">
    <caption class="d-sr-only">
      {{ t('alerts.title') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('alerts.columns.time') }}
        </th>
        <th scope="col">
          {{ t('alerts.columns.kind') }}
        </th>
        <th scope="col">
          {{ t('alerts.columns.monitor') }}
        </th>
        <th scope="col">
          {{ t('alerts.columns.message') }}
        </th>
        <th scope="col">
          {{ t('alerts.columns.duration') }}
        </th>
        <th scope="col">
          {{ t('alerts.columns.deliveries') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in list.items.value" :key="row.id" :data-test="`alert-row-${row.id}`">
        <td class="text-no-wrap">
          <DateTime :value="row.created_at" />
        </td>
        <td>
          {{ t(`alerts.kinds.${row.kind}`) }}
          <StatusChip v-if="row.status" :status="row.status" size="x-small" class="ml-1" />
        </td>
        <td>
          <router-link
            v-if="row.monitor"
            :to="{ name: 'ws.monitor', params: { workspace: slug, monitor: String(row.monitor.id) } }"
          >
            {{ row.monitor.name }}
          </router-link>
          <span v-else class="text-medium-emphasis">{{ t('alerts.noMonitor') }}</span>
        </td>
        <td>
          {{ row.message }}
          <div v-if="row.suppressed_by_monitor_id !== null" class="text-caption text-medium-emphasis">
            {{ t('alerts.suppressed') }}
          </div>
        </td>
        <td class="text-no-wrap">
          {{ duration(row.duration_seconds) }}
        </td>
        <td>
          <ul class="deliveries">
            <li v-for="delivery in row.deliveries" :key="delivery.id" class="mb-1">
              <DeliveryStatusChip :status="delivery.status" />
              {{ delivery.channel?.name ?? t('alerts.deletedChannel') }}
              <span v-if="delivery.attempts > 1" class="text-caption">({{ t('alerts.attempts', { n: delivery.attempts }) }})</span>
              <div v-if="delivery.last_error" class="text-caption text-medium-emphasis">
                {{ delivery.last_error }}
              </div>
            </li>
            <li v-if="row.deliveries.length === 0" class="text-medium-emphasis">
              {{ t('alerts.noDeliveries') }}
            </li>
          </ul>
        </td>
      </tr>
    </tbody>
  </v-table>
  <EmptyState
    v-else-if="!list.loading.value && list.error.value === null"
    :icon="mdiBellOff"
    :title="t('alerts.empty')"
  />
  <div class="d-flex justify-center mt-2">
    <v-btn v-if="list.hasMore.value && list.items.value.length > 0" data-test="alerts-more" :loading="list.loading.value" variant="text" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>
</template>

<style scoped>
.deliveries {
  list-style: none;
  padding: 0;
}
</style>
