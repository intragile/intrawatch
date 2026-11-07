<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Maintenance } from '../../api/types'
import type { MaintenanceForm } from '../../components/maintenances/model'
import { mdiStopCircleOutline, mdiTrashCanOutline } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import AffectedPicker from '../../components/incidents/AffectedPicker.vue'
import {
  createBody,
  formFromMaintenance,
  newMaintenanceForm,
  patchBody,
  STRATEGY_FIELDS,
} from '../../components/maintenances/model'
import ScheduleFields from '../../components/maintenances/ScheduleFields.vue'
import StateChips from '../../components/maintenances/StateChips.vue'
import WindowPreview from '../../components/maintenances/WindowPreview.vue'
import { clone, deepEqual } from '../../components/monitors/forms/model'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useLeaveGuard } from '../../composables/useLeaveGuard'
import { useNotify } from '../../composables/useNotify'
import { useSession } from '../../stores/session'
import { asApiError } from '../../utils/errors'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => session.current?.slug ?? String(route.params.workspace))
const workspaceZone = computed(() => session.current?.timezone ?? 'UTC')
const maintenanceId = computed(() => route.params.maintenance === 'new'
  ? null
  : Number(route.params.maintenance))
const isEdit = computed(() => maintenanceId.value !== null)
const canManage = computed(() => can('manageIncidents'))

const loaded = ref<Maintenance | null>(null)
const form = ref<MaintenanceForm | null>(null)
const initial = ref<MaintenanceForm | null>(null)
const loading = ref(true)
const loadError = ref<ApiError | null>(null)
const saving = ref(false)
const error = ref<ApiError | null>(null)

function apply(m: Maintenance): void {
  loaded.value = m
  form.value = formFromMaintenance(m, workspaceZone.value)
  initial.value = clone(form.value)
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    if (maintenanceId.value === null) {
      loaded.value = null
      form.value = newMaintenanceForm(workspaceZone.value)
      initial.value = clone(form.value)
    }
    else {
      apply(await unwrap<Maintenance>(api.GET('/workspaces/{workspace}/maintenances/{maintenance}', {
        params: {
          path: { workspace: slug.value, maintenance: maintenanceId.value },
        },
      })))
    }
  }
  catch (e) {
    loadError.value = asApiError(e)
  }
  finally {
    loading.value = false
  }
}

// Anyone who can view incidents can read a maintenance; only managers may start a new one.
if (!isEdit.value && !canManage.value) {
  void router.replace({
    name: 'ws.maintenances',
    params: { workspace: slug.value },
  })
}
else {
  onMounted(load)
  watch(maintenanceId, (next, previous) => {
    if (next !== previous) {
      form.value = null
      void load()
    }
  })
}

const dirty = computed(() => form.value !== null
  && initial.value !== null
  && !deepEqual(form.value, initial.value))
const leave = useLeaveGuard(dirty)

const fields = computed(() => fieldErrors(error.value))
/** Error roots of the controls on screen right now; every other key goes to the alert. */
const knownFields = computed(() => {
  const strategy = form.value?.strategy ?? 'once'
  const roots = [
    'title',
    'description',
    'strategy',
    'timezone',
    'active',
    'monitor_ids',
    'status_page_ids',
    ...STRATEGY_FIELDS[strategy],
  ]
  return Object.keys(error.value?.errors ?? {}).filter(key =>
    roots.some(root => key === root || key.startsWith(`${root}.`)))
})

async function save(): Promise<void> {
  const current = form.value
  const baseline = initial.value
  if (!current || !baseline || saving.value || !canManage.value)
    return
  error.value = null
  const id = maintenanceId.value
  const update = id === null
    ? null
    : patchBody(baseline, current)
  if (update !== null && Object.keys(update).length === 0) {
    notify(t('maintenances.noChanges'), 'info')
    return
  }
  saving.value = true
  try {
    if (update !== null && id !== null) {
      apply(await unwrap<Maintenance>(api.PATCH('/workspaces/{workspace}/maintenances/{maintenance}', {
        params: {
          path: { workspace: slug.value, maintenance: id },
        },
        body: update,
      })))
      notify(t('maintenances.updated'))
    }
    else {
      await unwrap<Maintenance>(api.POST('/workspaces/{workspace}/maintenances', {
        params: {
          path: { workspace: slug.value },
        },
        body: createBody(current),
      }))
      leave.allow()
      notify(t('maintenances.created'))
      await router.push({
        name: 'ws.maintenances',
        params: { workspace: slug.value },
      })
    }
  }
  catch (e) {
    leave.disallow()
    error.value = failure(e)
  }
  finally {
    saving.value = false
  }
}

function cancel(): void {
  void router.push({
    name: 'ws.maintenances',
    params: { workspace: slug.value },
  })
}

const canEnd = computed(() => canManage.value
  && loaded.value?.in_progress === true
  && loaded.value.strategy === 'once')
const ending = ref(false)
const endError = ref<ApiError | null>(null)
const endBusy = ref(false)

async function end(): Promise<void> {
  const id = maintenanceId.value
  if (id === null || endBusy.value)
    return
  endBusy.value = true
  endError.value = null
  try {
    apply(await unwrap<Maintenance>(api.POST('/workspaces/{workspace}/maintenances/{maintenance}/end', {
      params: {
        path: { workspace: slug.value, maintenance: id },
      },
    })))
    ending.value = false
    notify(t('maintenances.endNowDialog.ended'))
  }
  catch (e) {
    endError.value = failure(e)
  }
  finally {
    endBusy.value = false
  }
}

const removing = ref(false)
const removeError = ref<ApiError | null>(null)
const removeBusy = ref(false)

async function remove(): Promise<void> {
  const id = maintenanceId.value
  if (id === null || removeBusy.value)
    return
  removeBusy.value = true
  removeError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/maintenances/{maintenance}', {
      params: {
        path: { workspace: slug.value, maintenance: id },
      },
    }))
    leave.allow()
    notify(t('maintenances.delete.deleted'))
    await router.push({
      name: 'ws.maintenances',
      params: { workspace: slug.value },
    })
  }
  catch (e) {
    removeError.value = failure(e)
  }
  finally {
    removeBusy.value = false
  }
}
</script>

<template>
  <h1 class="text-h5 mb-4">
    {{ isEdit ? t('maintenances.editTitle') : t('maintenances.newTitle') }}
  </h1>

  <v-progress-linear v-if="loading" indeterminate :aria-label="t('maintenances.loading')" />
  <div v-else-if="loadError">
    <ProblemAlert :error="loadError" />
    <v-btn variant="tonal" data-test="retry" @click="load">
      {{ t('maintenances.retry') }}
    </v-btn>
  </div>

  <template v-else-if="form">
    <v-card max-width="860" class="mb-4">
      <v-form :disabled="!canManage" @submit.prevent="save">
        <v-card-text>
          <v-alert v-if="!canManage" type="info" variant="tonal" class="mb-4">
            {{ t('maintenances.readOnly') }}
          </v-alert>
          <ProblemAlert :error="error" :known-fields="knownFields" />

          <div v-if="loaded" class="mb-4">
            <StateChips :maintenance="loaded" />
          </div>

          <v-text-field
            v-model="form.title"
            name="maintenance_title"
            :label="`${t('maintenances.fields.title')} *`"
            maxlength="190"
            autocomplete="off"
            :error-messages="fields.title"
          />
          <v-textarea
            v-model="form.description"
            name="maintenance_description"
            :label="t('maintenances.fields.description')"
            rows="2"
            maxlength="5000"
            :error-messages="fields.description"
          />
          <v-switch
            v-model="form.active"
            name="maintenance_active"
            :label="t('maintenances.fields.active')"
            :hint="t('maintenances.hints.active')"
            persistent-hint
            color="primary"
            inset
            :error-messages="fields.active"
          />

          <ScheduleFields v-model="form" :errors="fields" />

          <h2 class="text-h6 mt-4 mb-2">
            {{ t('maintenances.targets.title') }}
          </h2>
          <AffectedPicker
            v-model:monitor-ids="form.monitor_ids"
            v-model:status-page-ids="form.status_page_ids"
            :slug="slug"
            :monitors-label="`${t('incidents.affected.monitors')}${isEdit ? '' : ' *'}`"
            :disabled="!canManage"
            :can-list-monitors="can('viewMonitors')"
            :monitors-error="fields.monitor_ids ?? Object.entries(fields).find(([k]) => k.startsWith('monitor_ids.'))?.[1]"
            :status-pages-error="fields.status_page_ids"
            :status-pages-hint="t('maintenances.targets.statusPagesHint')"
          />

          <template v-if="loaded">
            <h2 class="text-h6 mt-4 mb-2">
              {{ t('maintenances.preview.title') }}
            </h2>
            <WindowPreview
              :window="loaded.next_window"
              :in-progress="loaded.in_progress"
              :timezone="form.timezone"
            />
          </template>

          <p class="text-caption text-medium-emphasis mt-4">
            {{ t('maintenances.staleNote') }}
          </p>
        </v-card-text>
        <v-card-actions v-if="canManage">
          <v-btn
            v-if="isEdit"
            data-test="maintenance-delete"
            color="error"
            variant="text"
            :prepend-icon="mdiTrashCanOutline"
            @click="removing = true"
          >
            {{ t('common.delete') }}
          </v-btn>
          <v-btn
            v-if="canEnd"
            data-test="maintenance-end-now"
            variant="tonal"
            :prepend-icon="mdiStopCircleOutline"
            @click="ending = true"
          >
            {{ t('maintenances.endNow') }}
          </v-btn>
          <v-spacer />
          <v-btn variant="text" data-test="cancel" @click="cancel">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn
            type="submit"
            color="primary"
            variant="flat"
            data-test="maintenance-save"
            :loading="saving"
          >
            {{ isEdit ? t('common.save') : t('common.create') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </template>

  <ConfirmDialog
    v-model="ending"
    :title="t('maintenances.endNowDialog.title')"
    :confirm-label="t('maintenances.endNowDialog.confirm')"
    :loading="endBusy"
    destructive
    @confirm="end"
  >
    <ProblemAlert :error="endError" />
    {{ t('maintenances.endNowDialog.text', { title: loaded?.title ?? '' }) }}
  </ConfirmDialog>

  <ConfirmDialog
    v-model="removing"
    :title="t('maintenances.delete.title')"
    :confirm-label="t('common.delete')"
    :loading="removeBusy"
    destructive
    @confirm="remove"
  >
    <ProblemAlert :error="removeError" />
    {{ t('maintenances.delete.text', { title: loaded?.title ?? '' }) }}
  </ConfirmDialog>

  <ConfirmDialog
    v-model="leave.open.value"
    :title="t('maintenances.leave.title')"
    :text="t('maintenances.leave.text')"
    :confirm-label="t('maintenances.leave.confirm')"
    destructive
    @confirm="leave.confirm"
  />
</template>
