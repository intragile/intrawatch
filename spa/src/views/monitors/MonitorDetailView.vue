<script setup lang="ts">
import type { ApiError } from '../../api'
import type { AlertEvent, CheckResult, Monitor, MonitorEvent, MonitorRollup } from '../../api/types'
import type { ChartPoint, RangeKey } from '../../components/monitors/chartData'
import { mdiArrowLeft } from '@mdi/js'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, unwrap, useCursorList } from '../../api'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import StatusChip from '../../components/common/StatusChip.vue'
import AlertsTable from '../../components/monitors/AlertsTable.vue'
import { pointsFromRollups, RANGES, withLive } from '../../components/monitors/chartData'
import EventsTable from '../../components/monitors/EventsTable.vue'
import LatencyChart from '../../components/monitors/LatencyChart.vue'
import MonitorActions from '../../components/monitors/MonitorActions.vue'
import PushUrlCard from '../../components/monitors/PushUrlCard.vue'
import ResultsTable from '../../components/monitors/ResultsTable.vue'
import UptimeStats from '../../components/monitors/UptimeStats.vue'
import { useCan } from '../../composables/useCan'
import { useWorkspaceLive } from '../../live/useWorkspaceLive'
import { useMonitors } from '../../stores/monitors'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'
import { asApiError } from '../../utils/errors'

/** Rollup page size (the API maximum) and the page cap: 1,440 minute buckets fit in eight pages. */
const ROLLUP_LIMIT = 200
const ROLLUP_PAGES = 10
/** Live samples kept for the 24 h chart. */
const MAX_LIVE = 1440

type Section = 'results' | 'events' | 'alerts'

const { t } = useI18n()
const route = useRoute()
const session = useSession()
const store = useMonitors()
const live = useWorkspaceLive()
const can = useCan()

const slug = computed(() => session.current?.slug ?? '')
const monitorId = computed(() => Number(route.params.monitor))

const loaded = ref<Monitor | null>(null)
const monitor = computed(() => store.items.get(monitorId.value) ?? loaded.value)
const loading = ref(true)
const loadError = ref<ApiError | null>(null)
const checkable = ref(false)

function emptyRanges(): Record<RangeKey, MonitorRollup[] | null> {
  return {
    '24h': null,
    '7d': null,
    '30d': null,
    '90d': null,
  }
}
const rollups = ref(emptyRanges())
const rollupError = ref<ApiError | null>(null)
const range = ref<RangeKey>('24h')
const livePoints = ref<ChartPoint[]>([])

const section = ref<Section>('results')
const visited = new Set<Section>(['results'])

function history<T>(path: 'results' | 'events' | 'alerts') {
  return useCursorList<T>(async (cursor) => {
    const params = {
      path: { workspace: slug.value, monitor: monitorId.value },
      query: { cursor: cursor ?? undefined },
    }
    const result = path === 'results'
      ? await bodyOf(api.GET('/workspaces/{workspace}/monitors/{monitor}/results', { params }))
      : path === 'events'
        ? await bodyOf(api.GET('/workspaces/{workspace}/monitors/{monitor}/events', { params }))
        : await bodyOf(api.GET('/workspaces/{workspace}/monitors/{monitor}/alerts', { params }))
    return result as unknown as { data: T[], meta: { next_cursor: string | null } }
  })
}

const results = history<CheckResult>('results')
const events = history<MonitorEvent>('events')
const alerts = history<AlertEvent>('alerts')

const canSeeAlerts = computed(() => can('viewNotifications'))

const chartPoints = computed(() => {
  const base = pointsFromRollups(rollups.value[range.value] ?? [])
  return range.value === '24h'
    ? withLive(base, livePoints.value)
    : base
})
const chartLoading = computed(() => rollups.value[range.value] === null && rollupError.value === null)

async function fetchRollups(key: RangeKey): Promise<MonitorRollup[]> {
  const spec = RANGES.find(r => r.key === key)
  if (!spec)
    return []
  const to = new Date()
  const from = new Date(to.getTime() - spec.hours * 3_600_000)
  const out: MonitorRollup[] = []
  let cursor: string | undefined
  for (let i = 0; i < ROLLUP_PAGES; i++) {
    const page = await bodyOf(api.GET('/workspaces/{workspace}/monitors/{monitor}/rollups', {
      params: {
        path: { workspace: slug.value, monitor: monitorId.value },
        query: {
          bucket: spec.bucket,
          from: from.toISOString(),
          to: to.toISOString(),
          limit: ROLLUP_LIMIT,
          cursor,
        },
      },
    }))
    out.push(...page.data)
    if (page.meta.next_cursor === null)
      break
    cursor = page.meta.next_cursor
  }
  return out
}

/** Bumped per load so responses for a monitor or refresh that is no longer current are dropped. */
let generation = 0

async function loadRollups(): Promise<void> {
  const mine = generation
  rollupError.value = null
  livePoints.value = []
  await Promise.all(RANGES.map(async (r) => {
    try {
      const data = await fetchRollups(r.key)
      if (mine === generation)
        rollups.value[r.key] = data
    }
    catch (e) {
      if (mine === generation)
        rollupError.value = asApiError(e)
    }
  }))
}

async function load(): Promise<void> {
  const mine = ++generation
  loading.value = true
  loadError.value = null
  loaded.value = null
  rollups.value = emptyRanges()
  visited.clear()
  visited.add('results')
  section.value = 'results'
  try {
    const [fetched, types] = await Promise.all([
      unwrap<Monitor>(api.GET('/workspaces/{workspace}/monitors/{monitor}', {
        params: {
          path: { workspace: slug.value, monitor: monitorId.value },
        },
      })),
      bodyOf(api.GET('/monitor-types')).catch(() => []),
    ])
    if (mine !== generation)
      return
    loaded.value = fetched
    store.upsert(fetched)
    checkable.value = types.some(i => i.type === fetched.type && i.scheduled)
  }
  catch (e) {
    if (mine === generation)
      loadError.value = asApiError(e)
    return
  }
  finally {
    if (mine === generation)
      loading.value = false
  }
  void loadRollups()
  void results.reload()
}

function applyUpdate(next: Monitor): void {
  loaded.value = next
  store.upsert(next)
}

function onRotated(pushUrl: string | null): void {
  if (loaded.value === null)
    return
  const next = {
    ...(monitor.value ?? loaded.value),
    push_url: pushUrl,
    has_push_token: true,
  }
  applyUpdate(next)
}

function refreshSection(which: Section): void {
  const list = which === 'results'
    ? results
    : which === 'events'
      ? events
      : alerts
  void list.reload()
}

function openSection(next: Section): void {
  if (!visited.has(next)) {
    visited.add(next)
    refreshSection(next)
  }
}

const stops = [
  live.on('checks', (e) => {
    const mine = e.checks.filter(c => c.monitor_id === monitorId.value)
    if (mine.length === 0)
      return
    livePoints.value = [
      ...livePoints.value,
      ...mine.map(c => ({
        t: Math.floor(Date.parse(c.checked_at) / 1000),
        avg: c.latency_ms,
        min: c.latency_ms,
        max: c.latency_ms,
      })),
    ].slice(-MAX_LIVE)
  }),
  live.on('status_changed', (e) => {
    if (e.monitor_id === monitorId.value && visited.has('events'))
      void events.reloadLoaded()
  }),
  live.on('alert', () => {
    if (visited.has('alerts'))
      void alerts.reloadLoaded()
  }),
  live.onResync(() => {
    void unwrap<Monitor>(api.GET('/workspaces/{workspace}/monitors/{monitor}', {
      params: {
        path: { workspace: slug.value, monitor: monitorId.value },
      },
    })).then(applyUpdate, () => {})
    void loadRollups()
    for (const which of visited) {
      void (which === 'results'
        ? results
        : which === 'events'
          ? events
          : alerts).reloadLoaded()
    }
  }),
]
onBeforeUnmount(() => {
  generation++
  for (const stop of stops)
    stop()
})

watch(monitorId, () => {
  results.items.value = []
  void load()
}, { immediate: false })
watch(section, openSection)

void load()
</script>

<template>
  <v-btn
    variant="text"
    :prepend-icon="mdiArrowLeft"
    :to="{ name: 'ws.monitors', params: { workspace: slug } }"
    class="mb-2"
  >
    {{ t('monitorDetail.back') }}
  </v-btn>

  <v-progress-linear v-if="loading" indeterminate :aria-label="t('monitorDetail.loading')" />
  <template v-else-if="loadError !== null">
    <ProblemAlert :error="loadError" />
    <v-btn variant="tonal" @click="load">
      {{ t('monitors.retry') }}
    </v-btn>
  </template>
  <EmptyState v-else-if="monitor === null" :title="t('monitorDetail.missing')" />

  <template v-else>
    <header class="d-flex flex-wrap align-center ga-3 mb-4">
      <h1 class="text-h5">
        {{ monitor.name }}
      </h1>
      <StatusChip :status="monitor.state.status" />
      <v-chip size="small" label>
        {{ t(`monitors.types.${monitor.type}`) }}
      </v-chip>
      <v-chip v-for="tag in monitor.tags" :key="tag.id" size="small" label :color="tag.color">
        {{ tag.value ? `${tag.name}: ${tag.value}` : tag.name }}
      </v-chip>
    </header>

    <p v-if="monitor.description" class="mb-2">
      {{ monitor.description }}
    </p>
    <dl class="d-flex flex-wrap ga-6 mb-4 text-body-2">
      <div>
        <dt class="text-caption">
          {{ t('monitorDetail.since') }}
        </dt>
        <dd><DateTime :value="monitor.state.since" /></dd>
      </div>
      <div>
        <dt class="text-caption">
          {{ t('monitorDetail.lastCheck') }}
        </dt>
        <dd><DateTime :value="monitor.state.last_checked_at" /></dd>
      </div>
      <div>
        <dt class="text-caption">
          {{ t('monitorDetail.latency') }}
        </dt>
        <dd>{{ monitor.state.last_latency_ms === null ? '—' : t('monitors.latencyMs', { ms: monitor.state.last_latency_ms }) }}</dd>
      </div>
      <div v-if="monitor.state.last_message">
        <dt class="text-caption">
          {{ t('monitorDetail.message') }}
        </dt>
        <dd>{{ monitor.state.last_message }}</dd>
      </div>
    </dl>

    <MonitorActions :monitor="monitor" :checkable="checkable" class="mb-6" @updated="applyUpdate" />

    <PushUrlCard
      v-if="monitor.type === 'push' || monitor.type === 'cron'"
      :monitor="monitor"
      class="mb-6"
      @rotated="onRotated"
    />

    <ProblemAlert :error="rollupError" />
    <LatencyChart v-model:range="range" :points="chartPoints" :loading="chartLoading" class="mb-6" />
    <UptimeStats :ranges="rollups" class="mb-6" />

    <v-tabs v-model="section" :aria-label="t('monitorDetail.history')">
      <v-tab value="results" data-test="tab-results">
        {{ t('monitorDetail.results.title') }}
      </v-tab>
      <v-tab value="events" data-test="tab-events">
        {{ t('monitorDetail.events.title') }}
      </v-tab>
      <v-tab v-if="canSeeAlerts" value="alerts" data-test="tab-alerts">
        {{ t('monitorDetail.alerts.title') }}
      </v-tab>
    </v-tabs>
    <div class="mt-4">
      <div v-if="section === 'results'">
        <v-btn size="small" variant="text" class="mb-2" @click="results.reload()">
          {{ t('monitorDetail.refresh') }}
        </v-btn>
        <ResultsTable :list="results" />
      </div>
      <EventsTable v-else-if="section === 'events'" :list="events" />
      <AlertsTable v-else-if="section === 'alerts' && canSeeAlerts" :list="alerts" />
    </div>
  </template>
</template>
