<script setup lang="ts">
import type { ApiError } from '../../api'
import type { ImportRun } from '../../api/types'
import { mdiDeleteOutline, mdiFileDocumentOutline } from '@mdi/js'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, unwrap, useCursorList } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import ImportReport from '../../components/import/ImportReport.vue'
import ImportUpload from '../../components/import/ImportUpload.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useRefetchOnReturn } from '../../composables/useRefetchOnReturn'
import { bodyOf } from '../../utils/cursorPage'

type Status = ImportRun['status']

const POLL_MS = 2000
const WORKING: Status[] = ['pending', 'analyzing', 'running']
const OPEN: Status[] = [...WORKING, 'analyzed']

const { t } = useI18n()
const route = useRoute()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => String(route.params.workspace))
const allowed = can('update')

const current = ref<ImportRun | null>(null)
const error = ref<ApiError | null>(null)

const list = useCursorList(async (cursor) => {
  const result = await bodyOf(api.GET('/workspaces/{workspace}/imports', {
    params: {
      path: { workspace: slug.value },
      query: { cursor: cursor ?? undefined },
    },
  }))
  return {
    data: result.data,
    meta: result.meta,
  }
})

const working = computed(() => current.value !== null && WORKING.includes(current.value.status))
const blocked = computed(() => current.value !== null && OPEN.includes(current.value.status))
const report = computed(() => current.value?.report ?? null)

function statusColor(status: Status): string {
  switch (status) {
    case 'completed': return 'success'
    case 'failed': return 'error'
    case 'analyzed': return 'info'
    default: return 'default'
  }
}

function sizeLabel(bytes: number | null): string {
  if (bytes === null)
    return ''
  return bytes >= 1024 * 1024
    ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

// Polling runs only while the import is being processed and the tab is visible.
let timer: ReturnType<typeof setInterval> | null = null
let refreshing = false

function stopPolling(): void {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}

async function refresh(): Promise<void> {
  const id = current.value?.id
  if (!id || refreshing)
    return
  refreshing = true
  try {
    const before = current.value?.status
    // The generated types of the show operation declare file_name/file_size as string, unlike every other
    // import response (string|null, number|null); the payload is the same resource.
    const fresh = await unwrap<unknown>(api.GET('/workspaces/{workspace}/imports/{import}', {
      params: {
        path: { workspace: slug.value, import: id },
      },
    })) as ImportRun
    if (current.value?.id !== id)
      return
    current.value = fresh
    error.value = null
    if (before !== fresh.status && !WORKING.includes(fresh.status))
      void list.reloadLoaded()
  }
  catch (e) {
    error.value = failure(e)
  }
  finally {
    refreshing = false
  }
}

watch(working, (on) => {
  if (!on) {
    stopPolling()
    return
  }
  if (timer === null) {
    timer = setInterval(() => {
      if (!document.hidden)
        void refresh()
    }, POLL_MS)
  }
}, { immediate: true })

function onVisible(): void {
  if (!document.hidden && working.value)
    void refresh()
}
document.addEventListener('visibilitychange', onVisible)
onBeforeUnmount(() => {
  stopPolling()
  document.removeEventListener('visibilitychange', onVisible)
})
useRefetchOnReturn(() => {
  if (working.value)
    void refresh()
}, () => document.hidden)

async function load(): Promise<void> {
  await list.reload()
  const latest = list.items.value[0]
  if (latest && OPEN.includes(latest.status)) {
    current.value = latest
    await refresh()
  }
}
if (allowed)
  void load()

function onUploaded(created: ImportRun): void {
  current.value = created
  error.value = null
  void list.reload()
}

const confirmingRun = ref(false)
const runBusy = ref(false)
const runError = ref<ApiError | null>(null)

const toCreate = computed(() => Object.entries(report.value?.counts ?? {})
  .map(([entity, outcomes]) => ({ entity, n: outcomes.would_create ?? 0 }))
  .filter(item => item.n > 0))
const summary = computed(() => toCreate.value
  .map(({ entity, n }) => t(`import.entityCount.${entity}`, n))
  .join(', '))

async function startRun(): Promise<void> {
  const target = current.value
  if (!target || runBusy.value)
    return
  runBusy.value = true
  runError.value = null
  try {
    current.value = await unwrap<ImportRun>(api.POST('/workspaces/{workspace}/imports/{import}/run', {
      params: {
        path: { workspace: slug.value, import: target.id },
      },
    }))
    confirmingRun.value = false
    void list.reloadLoaded()
  }
  catch (e) {
    runError.value = failure(e)
    void refresh()
  }
  finally {
    runBusy.value = false
  }
}

const deleting = ref<ImportRun | null>(null)
const deleteBusy = ref(false)
const deleteError = ref<ApiError | null>(null)
const confirmDelete = computed({
  get: () => deleting.value !== null,
  set: (open) => {
    if (!open) {
      deleting.value = null
      deleteError.value = null
    }
  },
})

function deletable(item: ImportRun): boolean {
  return !WORKING.includes(item.status)
}

async function remove(): Promise<void> {
  const item = deleting.value
  if (!item || deleteBusy.value)
    return
  deleteBusy.value = true
  deleteError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/imports/{import}', {
      params: {
        path: { workspace: slug.value, import: item.id },
      },
    }))
    if (current.value?.id === item.id)
      current.value = null
    deleting.value = null
    notify(t('import.previous.deleted'))
    await list.reloadLoaded()
  }
  catch (e) {
    deleteError.value = failure(e)
  }
  finally {
    deleteBusy.value = false
  }
}

watch(slug, () => {
  stopPolling()
  current.value = null
  void load()
})
</script>

<template>
  <h1 class="text-h5 mb-2">
    {{ t('import.title') }}
  </h1>

  <EmptyState v-if="!allowed" :title="t('common.noAccess')" />
  <template v-else>
    <p class="mb-4">
      {{ t('import.intro') }}
    </p>

    <ImportUpload v-if="!blocked" :workspace="slug" @uploaded="onUploaded" />
    <v-alert v-else-if="!report" type="info" variant="tonal" class="mb-4" max-width="960">
      {{ t('import.blocked') }}
    </v-alert>

    <ProblemAlert :error="error" />

    <v-card v-if="current" class="mb-4" max-width="960">
      <v-card-title tag="h2" class="text-h6 d-flex align-center ga-2">
        {{ current.status === 'completed' ? t('import.report.titleDone') : t('import.report.title') }}
        <v-chip data-test="import-status" :color="statusColor(current.status)" size="small" label>
          {{ t(`import.status.${current.status}`) }}
        </v-chip>
      </v-card-title>
      <v-card-text>
        <p v-if="working" role="status" class="mb-3">
          <v-progress-circular indeterminate size="18" width="2" class="mr-2" />
          {{ t('import.working', { status: t(`import.status.${current.status}`) }) }}
        </p>
        <v-alert v-if="current.status === 'failed'" type="error" variant="tonal" class="mb-3" :title="t('import.failedTitle')">
          {{ current.error }}
        </v-alert>
        <v-alert v-if="current.status === 'completed'" data-test="import-done" type="success" variant="tonal" class="mb-3" :title="t('import.done.title')">
          <router-link :to="{ name: 'ws.monitors', params: { workspace: slug } }">
            {{ t('import.done.link') }}
          </router-link>
        </v-alert>
        <ImportReport v-if="report" :report="report" />
      </v-card-text>
      <v-card-actions v-if="current.status === 'analyzed'">
        <p v-if="toCreate.length === 0" class="text-medium-emphasis ml-2">
          {{ t('import.run.nothing') }}
        </p>
        <v-spacer />
        <v-btn data-test="import-run" color="primary" variant="flat" @click="confirmingRun = true">
          {{ t('import.run.action') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <h2 class="text-h6 mb-2 mt-6">
      {{ t('import.previous.title') }}
    </h2>
    <ProblemAlert :error="list.error.value" />
    <v-table v-if="list.items.value.length > 0" max-width="960">
      <thead>
        <tr>
          <th scope="col">
            {{ t('import.previous.status') }}
          </th>
          <th scope="col">
            {{ t('import.previous.date') }}
          </th>
          <th scope="col">
            {{ t('import.previous.file') }}
          </th>
          <th scope="col">
            {{ t('import.previous.actions') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in list.items.value" :key="item.id">
          <td>
            <v-chip :color="statusColor(item.status)" size="x-small" label>
              {{ t(`import.status.${item.status}`) }}
            </v-chip>
          </td>
          <td>
            <DateTime :value="item.created_at" />
          </td>
          <td>
            {{ item.file_name }}
            <span class="text-medium-emphasis">{{ sizeLabel(item.file_size) }}</span>
          </td>
          <td>
            <v-btn
              :data-test="`view-${item.id}`"
              :icon="mdiFileDocumentOutline"
              :aria-label="`${t('import.previous.view')}: ${item.file_name ?? item.id}`"
              variant="text"
              size="small"
              @click="current = item"
            />
            <v-btn
              :data-test="`delete-${item.id}`"
              :icon="mdiDeleteOutline"
              :aria-label="`${t('import.previous.delete')}: ${item.file_name ?? item.id}`"
              :disabled="!deletable(item)"
              variant="text"
              color="error"
              size="small"
              @click="deleting = item"
            />
          </td>
        </tr>
      </tbody>
    </v-table>
    <EmptyState v-else-if="!list.loading.value && !list.error.value" :title="t('import.previous.empty')" />
    <div v-if="list.hasMore.value && list.items.value.length > 0" class="mt-4">
      <v-btn data-test="load-more" variant="tonal" :loading="list.loading.value" @click="list.loadMore()">
        {{ t('common.loadMore') }}
      </v-btn>
    </div>

    <ConfirmDialog
      v-model="confirmingRun"
      :title="t('import.run.title')"
      :confirm-label="t('import.run.start')"
      :loading="runBusy"
      @confirm="startRun"
    >
      <ProblemAlert :error="runError" />
      {{ summary === '' ? t('import.run.nothing') : t('import.run.confirm', { summary }) }}
    </ConfirmDialog>

    <ConfirmDialog
      v-model="confirmDelete"
      :title="t('import.previous.deleteTitle')"
      :confirm-label="t('import.previous.delete')"
      :loading="deleteBusy"
      destructive
      @confirm="remove"
    >
      <ProblemAlert :error="deleteError" />
      {{ t('import.previous.deleteConfirm', { name: deleting?.file_name ?? '' }) }}
    </ConfirmDialog>
  </template>
</template>
