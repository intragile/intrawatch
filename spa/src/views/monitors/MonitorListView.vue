<script setup lang="ts">
import type { ApiError, CursorList } from '../../api'
import type { Monitor, MonitorStatus, MonitorType } from '../../api/types'
import type { BulkAction } from '../../components/monitors/BulkBar.vue'
import type { MonitorFilterValues } from '../../components/monitors/filters'
import { mdiPlus, mdiServerOff } from '@mdi/js'
import { computed, onBeforeUnmount, onMounted, ref, shallowReactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, useCursorList } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import BulkBar from '../../components/monitors/BulkBar.vue'
import { BULK_MAX_IDS } from '../../components/monitors/filters'
import MonitorFilters from '../../components/monitors/MonitorFilters.vue'
import MonitorRow from '../../components/monitors/MonitorRow.vue'
import StatusSummary from '../../components/monitors/StatusSummary.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useWorkspaceLive } from '../../live/useWorkspaceLive'
import { useMonitors } from '../../stores/monitors'
import { bodyOf } from '../../utils/cursorPage'

/** Page size of every monitor list request. */
const PAGE_LIMIT = 100
/** How often relative "since" times are recomputed. */
const NOW_REFRESH_MS = 30_000

const TYPES: MonitorType[] = [
  'http',
  'tcp',
  'ping',
  'dns',
  'tls',
  'domain',
  'push',
  'cron',
  'group',
]
const STATUSES: MonitorStatus[] = [
  'up',
  'down',
  'degraded',
  'maintenance',
  'pending',
  'paused',
]

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()
const store = useMonitors()
const live = useWorkspaceLive()

const slug = computed(() => String(route.params.workspace))
const canManage = computed(() => can('manageMonitors'))

function single(value: unknown): string | null {
  const v = Array.isArray(value)
    ? value[0]
    : value
  return typeof v === 'string' && v !== ''
    ? v
    : null
}

// The URL query is the single source of truth for the filters.
const filters = computed<MonitorFilterValues>(() => {
  const q = route.query
  const type = single(q.type)
  const status = single(q.status)
  const tag = Number(single(q.tag))
  const active = single(q.active)
  return {
    type: TYPES.find(v => v === type) ?? null,
    status: STATUSES.find(v => v === status) ?? null,
    tag: Number.isInteger(tag) && tag > 0
      ? tag
      : null,
    q: single(q.q) ?? '',
    active: active === 'true' || active === 'false'
      ? active
      : null,
  }
})
const flat = computed(() => {
  const f = filters.value
  return f.type !== null || f.status !== null || f.tag !== null || f.q !== '' || f.active !== null
})

function setFilters(next: MonitorFilterValues): void {
  const query: Record<string, string> = {}
  if (next.type)
    query.type = next.type
  if (next.status)
    query.status = next.status
  if (next.tag !== null)
    query.tag = String(next.tag)
  if (next.q !== '')
    query.q = next.q
  if (next.active)
    query.active = next.active
  void router.replace({ query })
}

function fetchPage(parent: number | 'null' | null) {
  return async (cursor: string | null) => {
    const f = filters.value
    return bodyOf(api.GET('/workspaces/{workspace}/monitors', {
      params: {
        path: { workspace: slug.value },
        query: {
          cursor: cursor ?? undefined,
          limit: PAGE_LIMIT,
          ...(parent === null
            ? {
                type: f.type ?? undefined,
                status: f.status ?? undefined,
                tag: f.tag ?? undefined,
                q: f.q === ''
                  ? undefined
                  : f.q,
                active: f.active ?? undefined,
              }
            : {
                parent_id: String(parent),
              }),
        },
      },
    }))
  }
}

const list = useCursorList<Monitor>((cursor) => {
  // Without filters only the top level loads; group members load when a group is expanded.
  return fetchPage(flat.value
    ? null
    : 'null')(cursor)
})

const selected = ref(new Set<number>())

const children = shallowReactive(new Map<number, CursorList<Monitor>>())
const expanded = ref(new Set<number>())
// Child lists of collapsed groups that were not refreshed along with the others.
const stale = new Set<number>()

function toggleGroup(id: number): void {
  const open = new Set(expanded.value)
  if (open.delete(id)) {
    expanded.value = open
    // Rows that are no longer shown must not stay selected.
    const hidden = new Set((children.get(id)?.items.value ?? []).map(m => m.id))
    if ([...selected.value].some(sel => hidden.has(sel)))
      selected.value = new Set([...selected.value].filter(sel => !hidden.has(sel)))
    return
  }
  open.add(id)
  expanded.value = open
  let child = children.get(id)
  if (!child) {
    child = useCursorList<Monitor>(fetchPage(id))
    children.set(id, child)
    void child.loadMore()
  }
  else if (stale.delete(id)) {
    void child.reloadLoaded()
  }
  else if (child.error.value && child.items.value.length === 0) {
    void child.loadMore()
  }
}

interface MonitorEntry { kind: 'monitor', key: string, monitor: Monitor, depth: number }
interface MoreEntry { kind: 'more', key: string, group: number, loading: boolean }
interface FailedEntry { kind: 'failed', key: string, group: number, error: ApiError }
interface EmptyEntry { kind: 'none', key: string, group: number }
type Entry = MonitorEntry | MoreEntry | EmptyEntry | FailedEntry

function resolve(m: Monitor): Monitor {
  return store.items.get(m.id) ?? m
}

const entries = computed<Entry[]>(() => {
  const out: Entry[] = []
  for (const top of list.items.value) {
    out.push({
      kind: 'monitor',
      key: `m${top.id}`,
      monitor: resolve(top),
      depth: 0,
    })
    if (flat.value || top.type !== 'group' || !expanded.value.has(top.id))
      continue
    const child = children.get(top.id)
    if (!child)
      continue
    for (const m of child.items.value) {
      out.push({
        kind: 'monitor',
        key: `m${m.id}`,
        monitor: resolve(m),
        depth: 1,
      })
    }
    if (child.hasMore.value && child.items.value.length > 0) {
      out.push({
        kind: 'more',
        key: `more${top.id}`,
        group: top.id,
        loading: child.loading.value,
      })
    }
    else if (child.error.value && child.items.value.length === 0) {
      out.push({
        kind: 'failed',
        key: `failed${top.id}`,
        group: top.id,
        error: child.error.value,
      })
    }
    else if (!child.loading.value && child.items.value.length === 0) {
      out.push({ kind: 'none', key: `none${top.id}`, group: top.id })
    }
  }
  return out
})
const shown = computed(() => entries.value.flatMap(e => e.kind === 'monitor'
  ? [e.monitor]
  : []))

// Selection
const allSelected = computed(() => shown.value.length > 0 && shown.value.every(m => selected.value.has(m.id)))
const someSelected = computed(() => selected.value.size > 0 && !allSelected.value)

// Pages register in the live store so status patches reach the rows without a refetch.
const registered = new Set<number>()
const loaded = computed(() => {
  const all = [...list.items.value]
  for (const child of children.values())
    all.push(...child.items.value)
  return all
})
watch(loaded, (items) => {
  for (const m of items) {
    store.upsert(m)
    registered.add(m.id)
  }
}, { immediate: true })

function prune(): void {
  const present = new Set(loaded.value.map(m => m.id))
  for (const id of [...registered]) {
    if (!present.has(id)) {
      registered.delete(id)
      store.remove(id)
    }
  }
  // Only rows on screen can stay selected.
  const visible = new Set(shown.value.map(m => m.id))
  const kept = new Set([...selected.value].filter(id => visible.has(id)))
  if (kept.size !== selected.value.size)
    selected.value = kept
}

function releaseAll(): void {
  for (const id of registered)
    store.remove(id)
  registered.clear()
}

function toggleSelect(id: number): void {
  const next = new Set(selected.value)
  if (!next.delete(id))
    next.add(id)
  selected.value = next
}

function toggleAll(): void {
  selected.value = allSelected.value
    ? new Set()
    : new Set(shown.value.map(m => m.id))
}

// Reference data for the bulk bar
const tags = ref<Array<{ id: number, name: string }>>([])
const channels = ref<Array<{ id: number, name: string }> | null>(null)
let channelsRequested = false

async function loadTags(): Promise<void> {
  try {
    const result = await bodyOf(api.GET('/workspaces/{workspace}/tags', {
      params: {
        path: { workspace: slug.value },
        query: { limit: PAGE_LIMIT },
      },
    }))
    tags.value = result.data
  }
  catch {
    tags.value = []
  }
}

async function loadChannels(): Promise<void> {
  if (channelsRequested || !can('viewNotifications'))
    return
  channelsRequested = true
  try {
    const result = await bodyOf(api.GET('/workspaces/{workspace}/notification-channels', {
      params: {
        path: { workspace: slug.value },
        query: { limit: PAGE_LIMIT },
      },
    }))
    channels.value = result.data
  }
  catch {
    channelsRequested = false
  }
}

watch(() => selected.value.size, (size) => {
  if (size > 0 && canManage.value)
    void loadChannels()
})

// Refresh
async function reloadAll(): Promise<void> {
  const open = [...expanded.value]
  for (const id of children.keys()) {
    if (!expanded.value.has(id))
      stale.add(id)
  }
  await Promise.all([
    list.reloadLoaded(),
    ...open.map(id => children.get(id)?.reloadLoaded()),
  ])
  prune()
}

function resetAll(): void {
  children.clear()
  expanded.value = new Set()
  stale.clear()
  selected.value = new Set()
  void list.reload()
}

watch(() => JSON.stringify(filters.value), resetAll)
watch(slug, () => {
  releaseAll()
  channelsRequested = false
  channels.value = null
  void loadTags()
  resetAll()
})

const offResync = live.onResync(() => {
  void reloadAll()
})
function onFocus(): void {
  if (!list.loading.value)
    void reloadAll()
}

// Relative times
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  window.addEventListener('focus', onFocus)
  ticker = setInterval(() => {
    now.value = Date.now()
  }, NOW_REFRESH_MS)
})
onBeforeUnmount(() => {
  window.removeEventListener('focus', onFocus)
  offResync()
  if (ticker)
    clearInterval(ticker)
  releaseAll()
})

void list.loadMore()
void loadTags()

// Bulk
const bulkBusy = ref(false)
const bulkError = ref<ApiError | null>(null)
const confirmDelete = ref(false)

function runBulk(action: BulkAction, target: { tagId?: number, channelId?: number }): void {
  bulkError.value = null
  if (action === 'delete') {
    confirmDelete.value = true
    return
  }
  void executeBulk(action, target)
}

async function executeBulk(action: BulkAction, target: { tagId?: number, channelId?: number } = {}): Promise<void> {
  const ids = [...selected.value]
  if (bulkBusy.value || ids.length === 0 || ids.length > BULK_MAX_IDS)
    return
  bulkBusy.value = true
  try {
    const result = await bodyOf(api.POST('/workspaces/{workspace}/monitors/bulk', {
      params: {
        path: { workspace: slug.value },
      },
      body: {
        action,
        ids,
        ...(action === 'tag' || action === 'untag'
          ? { tag_id: target.tagId }
          : {}),
        ...(action === 'notify' || action === 'unnotify'
          ? { channel_id: target.channelId }
          : {}),
      },
    }))
    confirmDelete.value = false
    selected.value = new Set()
    notify(t('monitors.bulk.done', { count: result.affected }))
    await reloadAll()
  }
  catch (e) {
    // Close the dialog so the problem is not hidden behind it.
    confirmDelete.value = false
    bulkError.value = failure(e)
  }
  finally {
    bulkBusy.value = false
  }
}

function open(id: number): void {
  void router.push({
    name: 'ws.monitor',
    params: { workspace: slug.value, monitor: id },
  })
}

const columns = computed(() => canManage.value
  ? 8
  : 7)
</script>

<template>
  <div class="d-flex flex-wrap align-center ga-3 mb-4">
    <h1 class="text-h5">
      {{ t('monitors.title') }}
    </h1>
    <v-spacer />
    <v-btn
      v-if="canManage"
      data-test="new-monitor"
      color="primary"
      variant="flat"
      :prepend-icon="mdiPlus"
      :to="{ name: 'ws.monitor.new', params: { workspace: slug } }"
    >
      {{ t('monitors.new') }}
    </v-btn>
  </div>

  <MonitorFilters
    :model-value="filters"
    :tags="tags"
    :any-active="flat"
    @update:model-value="setFilters"
    @reset="setFilters({ type: null, status: null, tag: null, q: '', active: null })"
  />

  <StatusSummary :monitors="shown" />

  <BulkBar
    v-if="canManage && selected.size > 0"
    :count="selected.size"
    :busy="bulkBusy"
    :tags="tags"
    :channels="channels"
    @run="runBulk"
    @clear="selected = new Set()"
  />

  <ProblemAlert :error="bulkError" />
  <ProblemAlert :error="list.error.value" />

  <v-progress-linear v-if="list.loading.value" indeterminate :aria-label="t('monitors.loadingMore')" class="mb-2" />

  <v-table v-if="list.items.value.length > 0" density="comfortable">
    <caption class="d-sr-only">
      {{ t('monitors.list') }}
    </caption>
    <thead>
      <tr>
        <th v-if="canManage" scope="col" class="select-col">
          <input
            type="checkbox"
            data-test="select-all"
            :checked="allSelected"
            :indeterminate="someSelected"
            :aria-label="t('monitors.selectAll')"
            @change="toggleAll"
          >
        </th>
        <th scope="col">
          {{ t('monitors.columns.status') }}
        </th>
        <th scope="col">
          {{ t('monitors.columns.name') }}
        </th>
        <th scope="col">
          {{ t('monitors.columns.type') }}
        </th>
        <th scope="col">
          {{ t('monitors.columns.latency') }}
        </th>
        <th scope="col">
          {{ t('monitors.columns.since') }}
        </th>
        <th scope="col">
          {{ t('monitors.columns.tags') }}
        </th>
        <th scope="col">
          {{ t('monitors.columns.beats') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <template v-for="entry in entries" :key="entry.key">
        <MonitorRow
          v-if="entry.kind === 'monitor'"
          :monitor="entry.monitor"
          :beats="store.beats.get(entry.monitor.id)"
          :depth="entry.depth"
          :selectable="canManage"
          :selected="selected.has(entry.monitor.id)"
          :expandable="!flat && entry.depth === 0 && entry.monitor.type === 'group'"
          :expanded="expanded.has(entry.monitor.id)"
          :now="now"
          @select="toggleSelect(entry.monitor.id)"
          @expand="toggleGroup(entry.monitor.id)"
          @open="open(entry.monitor.id)"
        />
        <tr v-else-if="entry.kind === 'more'">
          <td :colspan="columns">
            <v-btn
              :data-test="`more-${entry.group}`"
              variant="text"
              size="small"
              :loading="entry.loading"
              @click="children.get(entry.group)?.loadMore()"
            >
              {{ t('monitors.more') }}
            </v-btn>
          </td>
        </tr>
        <tr v-else-if="entry.kind === 'failed'" :data-test="`failed-${entry.group}`">
          <td :colspan="columns">
            <ProblemAlert :error="entry.error" />
            <v-btn :data-test="`retry-${entry.group}`" variant="tonal" size="small" @click="children.get(entry.group)?.loadMore()">
              {{ t('monitors.retry') }}
            </v-btn>
          </td>
        </tr>
        <tr v-else>
          <td :colspan="columns" class="text-medium-emphasis">
            {{ t('monitors.noChildren') }}
          </td>
        </tr>
      </template>
    </tbody>
  </v-table>

  <EmptyState
    v-else-if="!list.loading.value && !list.error.value"
    :icon="mdiServerOff"
    :title="flat ? t('monitors.emptyFiltered') : t('monitors.empty')"
  />

  <div v-if="list.hasMore.value && list.items.value.length > 0" v-intersect="(visible: boolean) => visible && !list.loading.value && list.loadMore()" class="mt-4">
    <v-btn data-test="load-more" variant="tonal" :loading="list.loading.value" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>

  <ConfirmDialog
    v-model="confirmDelete"
    :title="t('monitors.bulk.deleteTitle')"
    :text="t('monitors.bulk.deleteConfirm', { count: selected.size })"
    :confirm-label="t('monitors.bulk.delete')"
    :loading="bulkBusy"
    destructive
    @confirm="executeBulk('delete')"
  />
</template>

<style scoped>
.select-col {
  width: 2.5rem;
}
</style>
