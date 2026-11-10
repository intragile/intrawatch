<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Monitor, StatusPageSummary } from '../../api/types'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../api'
import { bodyOf } from '../../utils/cursorPage'
import { asApiError } from '../../utils/errors'
import ProblemAlert from '../common/ProblemAlert.vue'

const props = withDefaults(defineProps<{
  slug: string
  disabled?: boolean
  /** Whether the monitors can be listed; without it they are shown by id. */
  canListMonitors?: boolean
  monitorsError?: string
  statusPagesError?: string
  /** Overrides the monitor picker's label. */
  monitorsLabel?: string
  /** Overrides the hint under the status page picker. */
  statusPagesHint?: string
  /** A persistent hint under the monitor picker. */
  monitorsHint?: string
  /** Hides the status page picker, for targets no status page may show. */
  hideStatusPages?: boolean
}>(), {
  disabled: false,
  canListMonitors: true,
  monitorsError: undefined,
  statusPagesError: undefined,
  monitorsLabel: undefined,
  statusPagesHint: undefined,
  monitorsHint: undefined,
  hideStatusPages: false,
})
/** How many monitors one search loads. */
const MONITOR_OPTIONS = 20
/** Selected monitors whose names are looked up individually; beyond this they show as ids. */
const NAME_LOOKUPS = 25
const STATUS_PAGE_LIMIT = 100
const STATUS_PAGE_PAGES = 5

const monitorIds = defineModel<number[]>('monitorIds', { required: true })
const statusPageIds = defineModel<number[]>('statusPageIds', { required: true })

const { t } = useI18n()

type NamedMonitor = Pick<Monitor, 'id' | 'name'>
type NamedPage = Pick<StatusPageSummary, 'id' | 'title'>

const monitors = ref<NamedMonitor[]>([])
const pages = ref<NamedPage[]>([])
const search = ref('')
const searching = ref(false)
const loadError = ref<ApiError | null>(null)
const pagesTruncated = ref(false)
const pagesLoading = ref(false)
let searchSeq = 0
let searchTimer: ReturnType<typeof setTimeout> | undefined

function remember(found: NamedMonitor[]): void {
  const byId = new Map(monitors.value.map(m => [m.id, m]))
  for (const m of found)
    byId.set(m.id, { id: m.id, name: m.name })
  monitors.value = [
    ...byId.values(),
  ]
}

async function searchMonitors(q: string): Promise<void> {
  if (!props.canListMonitors)
    return
  const mine = ++searchSeq
  searching.value = true
  loadError.value = null
  try {
    const result = await bodyOf(api.GET('/workspaces/{workspace}/monitors', {
      params: {
        path: { workspace: props.slug },
        query: {
          q: q.trim() === ''
            ? undefined
            : q.trim(),
          limit: MONITOR_OPTIONS,
        },
      },
    }))
    if (mine === searchSeq)
      remember(result.data)
  }
  catch (e) {
    loadError.value = asApiError(e)
  }
  finally {
    if (mine === searchSeq)
      searching.value = false
  }
}

watch(search, (q) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void searchMonitors(q), 250)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

/** Looks up the names of selected monitors the picker has not seen yet. */
async function resolveMonitorNames(): Promise<void> {
  if (!props.canListMonitors)
    return
  const known = new Set(monitors.value.map(m => m.id))
  const missing = monitorIds.value.filter(id => !known.has(id)).slice(0, NAME_LOOKUPS)
  const found = await Promise.all(missing.map(async (id) => {
    try {
      const result = await api.GET('/workspaces/{workspace}/monitors/{monitor}', {
        params: {
          path: { workspace: props.slug, monitor: id },
        },
      })
      const data = result.data?.data
      return data
        ? { id: data.id, name: data.name }
        : null
    }
    catch (e) {
      loadError.value = asApiError(e)
      return null
    }
  }))
  remember(found.filter((m): m is NamedMonitor => m !== null))
}

async function loadStatusPages(): Promise<void> {
  pagesLoading.value = true
  try {
    const all: NamedPage[] = []
    let cursor: string | undefined
    for (let i = 0; i < STATUS_PAGE_PAGES; i++) {
      const result = await bodyOf(api.GET('/workspaces/{workspace}/status-pages', {
        params: {
          path: { workspace: props.slug },
          query: { cursor, limit: STATUS_PAGE_LIMIT },
        },
      }))
      all.push(...result.data.map(p => ({ id: p.id, title: p.title })))
      cursor = result.meta.next_cursor ?? undefined
      if (cursor === undefined)
        break
    }
    pages.value = all
    pagesTruncated.value = cursor !== undefined
  }
  catch (e) {
    loadError.value = asApiError(e)
  }
  finally {
    pagesLoading.value = false
  }
}

watch(() => monitorIds.value.join(','), () => void resolveMonitorNames(), { immediate: true })
void loadStatusPages()

const monitorItems = computed(() => {
  const known = new Set(monitors.value.map(m => m.id))
  return [
    ...monitors.value,
    ...monitorIds.value
      .filter(id => !known.has(id))
      .map(id => ({
        id,
        name: t('incidents.affected.monitorById', { id }),
      })),
  ]
})

const pageItems = computed(() => {
  const known = new Set(pages.value.map(p => p.id))
  return [
    ...pages.value,
    ...statusPageIds.value
      .filter(id => !known.has(id))
      .map(id => ({
        id,
        title: t('incidents.affected.statusPageById', { id }),
      })),
  ]
})
</script>

<template>
  <ProblemAlert :error="loadError" />
  <v-autocomplete
    v-model="monitorIds"
    v-model:search="search"
    data-test="affected-monitors"
    name="affected_monitors"
    :items="monitorItems"
    item-title="name"
    item-value="id"
    :loading="searching"
    :label="monitorsLabel ?? t('incidents.affected.monitors')"
    :disabled="disabled"
    :error-messages="monitorsError"
    :hint="monitorsHint"
    :persistent-hint="monitorsHint !== undefined"
    multiple
    chips
    closable-chips
    no-filter
    @focus="searchMonitors(search)"
  />
  <v-autocomplete
    v-if="!hideStatusPages"
    v-model="statusPageIds"
    data-test="affected-status-pages"
    name="affected_status_pages"
    :items="pageItems"
    item-title="title"
    item-value="id"
    :loading="pagesLoading"
    :label="t('incidents.affected.statusPages')"
    :hint="pagesTruncated ? t('incidents.affected.truncated', { count: STATUS_PAGE_LIMIT * STATUS_PAGE_PAGES }) : (statusPagesHint ?? t('incidents.affected.hint'))"
    persistent-hint
    :disabled="disabled"
    :error-messages="statusPagesError"
    multiple
    chips
    closable-chips
  />
</template>
