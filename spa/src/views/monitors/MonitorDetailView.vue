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
import AlertsTable from '../../components/monitors/AlertsTable.vue'
import { pointsFromRollups, RANGES, withLive } from '../../components/monitors/chartData'
import EventsTable from '../../components/monitors/EventsTable.vue'
import HeartbeatBar from '../../components/monitors/HeartbeatBar.vue'
import LatencyChart from '../../components/monitors/LatencyChart.vue'
import MonitorActions from '../../components/monitors/MonitorActions.vue'
import PushUrlCard from '../../components/monitors/PushUrlCard.vue'
import ResultsTable from '../../components/monitors/ResultsTable.vue'
import { monitorTarget } from '../../components/monitors/target'
import UptimeStats from '../../components/monitors/UptimeStats.vue'
import TagChip from '../../components/tags/TagChip.vue'
import { useCan } from '../../composables/useCan'
import { useWorkspaceLive } from '../../live/useWorkspaceLive'
import { useMonitors } from '../../stores/monitors'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'
import { formatDuration } from '../../utils/duration'
import { asApiError } from '../../utils/errors'

/** Rollup page size (the API maximum) and the page cap: 1,440 minute buckets fit in eight pages. */
const ROLLUP_LIMIT = 200
const ROLLUP_PAGES = 10
/** Live samples kept for the 24 h chart. */
const MAX_LIVE = 1440

type Section = 'results' | 'events' | 'alerts'

const { t, locale } = useI18n()
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

// Hero: the status and how long it has lasted, refreshed every 30 s.
const now = ref(Date.now())
const ticker = setInterval(() => {
  now.value = Date.now()
}, 30_000)
onBeforeUnmount(() => clearInterval(ticker))

const STATUS_TEXT: Record<Monitor['state']['status'], string> = {
  up: 'text-success',
  down: 'text-error',
  degraded: 'text-warning',
  maintenance: 'text-maintenance',
  pending: 'text-idle',
  paused: 'text-idle',
}
const lasted = computed(() => {
  const since = Date.parse(monitor.value?.state.since ?? '')
  return Number.isNaN(since)
    ? ''
    : formatDuration(now.value - since, locale.value)
})
const target = computed(() => monitor.value
  ? monitorTarget(monitor.value)
  : '')

const isGroup = computed(() => monitor.value?.type === 'group')

/** Beats for the large strip: the live list's beats, else the newest loaded results. */
const STRIP_SLOTS = 50
const stripBeats = computed(() => {
  const fromStore = store.beats.get(monitorId.value)
  if (fromStore && fromStore.length > 0)
    return fromStore
  return results.items.value.slice(0, STRIP_SLOTS).reverse().map(r => ({
    status: r.status,
    latency_ms: r.latency_ms,
    at: r.checked_at,
  }))
})

/** No beats yet because the results are still on their way, not because there are none. */
const stripPending = computed(() => stripBeats.value.length === 0 && results.loading.value)

void load()
</script>

<template>
  <div class="detail">
    <v-btn
      variant="text"
      :prepend-icon="mdiArrowLeft"
      :to="{ name: 'ws.monitors', params: { workspace: slug } }"
      class="back d-lg-none"
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
      <header class="head">
        <div class="meta-row">
          <span class="tag">{{ t(`monitors.types.${monitor.type}`) }}</span>
          <span v-if="target" class="target">{{ target }}</span>
          <TagChip v-for="tag in monitor.tags" :key="tag.id" :name="tag.name" :color="tag.color" :value="tag.value" />
        </div>
        <div class="hero-row">
          <div class="hero-text">
            <h1 class="name">
              {{ monitor.name }}
            </h1>
            <p class="hero iw-display" data-test="hero" :data-status="monitor.state.status">
              <span :class="STATUS_TEXT[monitor.state.status]">{{ t(`status.${monitor.state.status}`) }}</span>
              <span v-if="lasted" class="hero-for">{{ t('monitorDetail.heroFor', { duration: lasted }) }}</span>
            </p>
          </div>
          <MonitorActions :monitor="monitor" :checkable="checkable" @updated="applyUpdate" />
        </div>
        <p v-if="monitor.state.last_message" class="message">
          <span class="muted">{{ t('monitorDetail.message') }}:</span> {{ monitor.state.last_message }}
        </p>
        <p v-if="monitor.description" class="description">
          {{ monitor.description }}
        </p>
      </header>

      <section class="strip" :aria-label="t('monitorDetail.strip.label')">
        <HeartbeatBar :beats="stripBeats" :slots="STRIP_SLOTS" size="large" :group="isGroup" :pending="stripPending" />
        <div v-if="!isGroup || stripBeats.length > 0" class="strip-scale">
          <span>{{ t(isGroup ? 'monitorDetail.strip.groupCount' : 'monitorDetail.strip.count', Math.min(stripBeats.length, STRIP_SLOTS)) }}</span>
          <span>{{ t('monitorDetail.strip.now') }}</span>
        </div>
      </section>

      <dl class="facts">
        <div>
          <dt>{{ t('monitorDetail.since') }}</dt>
          <dd><DateTime :value="monitor.state.since" /></dd>
        </div>
        <div>
          <dt>{{ t('monitorDetail.lastCheck') }}</dt>
          <dd><DateTime :value="monitor.state.last_checked_at" /></dd>
        </div>
        <div>
          <dt>{{ t('monitorDetail.latency') }}</dt>
          <dd>{{ monitor.state.last_latency_ms === null ? '—' : t('monitors.latencyMs', { ms: monitor.state.last_latency_ms }) }}</dd>
        </div>
      </dl>

      <PushUrlCard
        v-if="monitor.type === 'push' || monitor.type === 'cron'"
        :monitor="monitor"
        @rotated="onRotated"
      />

      <ProblemAlert :error="rollupError" />
      <UptimeStats :ranges="rollups" />
      <LatencyChart v-model:range="range" :points="chartPoints" :loading="chartLoading" />

      <section>
        <v-tabs v-model="section" :aria-label="t('monitorDetail.history')" class="history-tabs">
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
        <div class="mt-3">
          <div v-if="section === 'results'">
            <v-btn size="small" variant="text" class="mb-2" @click="results.reload()">
              {{ t('monitorDetail.refresh') }}
            </v-btn>
            <ResultsTable :list="results" />
          </div>
          <EventsTable v-else-if="section === 'events'" :list="events" />
          <AlertsTable v-else-if="section === 'alerts' && canSeeAlerts" :list="alerts" />
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.detail {
  display: flex;
  flex-direction: column;
  gap: 26px;
  max-width: 1100px;
}

.back {
  align-self: flex-start;
  margin-bottom: -14px;
}

.head {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.meta-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.tag {
  display: inline-flex;
  align-items: center;
  height: 24px;
  padding: 0 9px;
  border: 1px solid var(--iw-line);
  border-radius: 4px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  font-weight: 500;
}

.target {
  overflow-wrap: anywhere;
}

.hero-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px 24px;
}

.hero-text {
  min-width: 0;
}

.name {
  margin: 0 0 2px;
  font-size: 1.125rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.hero {
  margin: 0;
  font-size: 2.75rem;
  line-height: 1.05;
}

.hero-for {
  margin-inline-start: 0.3em;
  font-weight: 500;
}

.message,
.description {
  margin: 0;
  font-size: 1rem;
}

.muted {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.strip {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.strip-scale {
  display: flex;
  justify-content: space-between;
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 32px;
  margin: 0;
  font-size: 0.875rem;
}

.facts dt {
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.facts dd {
  margin: 0;
}

.history-tabs {
  border-bottom: 1px solid var(--iw-line);
}

@media (max-width: 599.98px) {
  .hero {
    font-size: 2.125rem;
  }
}
</style>
