<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Incident, IncidentImpact, IncidentStatus, IncidentTimelineEntry, IncidentUpdateBody } from '../../api/types'
import { mdiTrashCanOutline } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import DateTime from '../../components/common/DateTime.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import AffectedPicker from '../../components/incidents/AffectedPicker.vue'
import ImpactChip from '../../components/incidents/ImpactChip.vue'
import IncidentStatusChip from '../../components/incidents/IncidentStatusChip.vue'
import { INCIDENT_IMPACTS, timelineOf } from '../../components/incidents/model'
import UpdateComposer from '../../components/incidents/UpdateComposer.vue'
import UpdateTimeline from '../../components/incidents/UpdateTimeline.vue'
import { deepEqual } from '../../components/monitors/forms/model'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useLeaveGuard } from '../../composables/useLeaveGuard'
import { useNotify } from '../../composables/useNotify'
import { useRefetchOnReturn } from '../../composables/useRefetchOnReturn'
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
const incidentId = computed(() => Number(route.params.incident))
const canManage = computed(() => can('manageIncidents'))

interface Details {
  title: string
  impact: IncidentImpact
  monitor_ids: number[]
  status_page_ids: number[]
}

const incident = ref<Incident | null>(null)
const entries = ref<IncidentTimelineEntry[]>([])
const details = ref<Details | null>(null)
const initial = ref<Details | null>(null)
const loading = ref(true)
const posting = ref(false)
const loadError = ref<ApiError | null>(null)

function detailsOf(value: Incident): Details {
  return {
    title: value.title,
    impact: value.impact,
    monitor_ids: [...value.monitor_ids],
    status_page_ids: [...value.status_page_ids],
  }
}

/** The id lists compare as sets: the picker may reorder them. */
function sorted(value: Details): Details {
  return {
    ...value,
    monitor_ids: [...value.monitor_ids].sort((a, b) => a - b),
    status_page_ids: [...value.status_page_ids].sort((a, b) => a - b),
  }
}

const dirty = computed(() => details.value !== null
  && initial.value !== null
  && !deepEqual(sorted(details.value), sorted(initial.value)))
function apply(value: Incident): void {
  incident.value = value
  entries.value = timelineOf(value)
  // Edits in progress on the details survive a timeline refresh.
  if (details.value === null || !dirty.value) {
    details.value = detailsOf(value)
    initial.value = detailsOf(value)
  }
}

async function fetchIncident(): Promise<Incident> {
  return unwrap<Incident>(api.GET('/workspaces/{workspace}/incidents/{incident}', {
    params: {
      path: { workspace: slug.value, incident: incidentId.value },
    },
  }))
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    details.value = null
    apply(await fetchIncident())
  }
  catch (e) {
    loadError.value = asApiError(e)
  }
  finally {
    loading.value = false
  }
}

onMounted(load)
useRefetchOnReturn(() => {
  void fetchIncident().then(apply).catch(() => {})
}, () => loading.value || posting.value)
watch(incidentId, () => void load())

const leave = useLeaveGuard(dirty)

const impactItems = computed(() => INCIDENT_IMPACTS.map(value => ({
  value,
  title: t(`incidents.impact.${value}`),
})))

// Details: only the changed keys are sent.
const saving = ref(false)
const saveError = ref<ApiError | null>(null)
const detailFields = computed(() => fieldErrors(saveError.value))
const detailKnown = computed(() => Object.keys(saveError.value?.errors ?? {}).filter(key =>
  [
    'title',
    'impact',
    'monitor_ids',
    'status_page_ids',
  ].some(root => key === root || key.startsWith(`${root}.`))))

async function saveDetails(): Promise<void> {
  const current = details.value
  const baseline = initial.value
  if (!current || !baseline || saving.value || !canManage.value)
    return
  const body: IncidentUpdateBody = {}
  if (current.title !== baseline.title)
    body.title = current.title
  if (current.impact !== baseline.impact)
    body.impact = current.impact
  const now = sorted(current)
  const before = sorted(baseline)
  if (!deepEqual(now.monitor_ids, before.monitor_ids))
    body.monitor_ids = current.monitor_ids
  if (!deepEqual(now.status_page_ids, before.status_page_ids))
    body.status_page_ids = current.status_page_ids
  if (Object.keys(body).length === 0) {
    notify(t('incidents.details.noChanges'), 'info')
    return
  }
  saving.value = true
  saveError.value = null
  try {
    const saved = await unwrap<Incident>(api.PATCH('/workspaces/{workspace}/incidents/{incident}', {
      params: {
        path: { workspace: slug.value, incident: incidentId.value },
      },
      body,
    }))
    details.value = detailsOf(saved)
    initial.value = detailsOf(saved)
    // The PATCH response carries no timeline; the one on screen stays.
    incident.value = { ...saved, updates: incident.value?.updates ?? saved.updates }
    notify(t('incidents.details.saved'))
  }
  catch (e) {
    saveError.value = failure(e)
  }
  finally {
    saving.value = false
  }
}

// Timeline: post, edit and delete.
const composer = ref<InstanceType<typeof UpdateComposer> | null>(null)
const postError = ref<ApiError | null>(null)

async function post(update: { status: IncidentStatus, message: string }): Promise<void> {
  if (posting.value)
    return
  posting.value = true
  postError.value = null
  try {
    await unwrap<IncidentTimelineEntry>(api.POST('/workspaces/{workspace}/incidents/{incident}/updates', {
      params: {
        path: { workspace: slug.value, incident: incidentId.value },
      },
      body: update,
    }))
    composer.value?.reset()
    notify(t('incidents.composer.posted'))
    apply(await fetchIncident())
  }
  catch (e) {
    postError.value = failure(e)
  }
  finally {
    posting.value = false
  }
}

const editing = ref<IncidentTimelineEntry | null>(null)
const editMessage = ref('')
const editError = ref<ApiError | null>(null)
const editBusy = ref(false)
const editOpen = computed({
  get: () => editing.value !== null,
  set: (open) => {
    if (!open) {
      editing.value = null
      editError.value = null
    }
  },
})

function startEdit(entry: IncidentTimelineEntry): void {
  editing.value = entry
  editMessage.value = entry.message
  editError.value = null
}

async function saveEdit(): Promise<void> {
  const entry = editing.value
  if (!entry || editBusy.value)
    return
  editBusy.value = true
  editError.value = null
  try {
    await unwrap<IncidentTimelineEntry>(api.PATCH('/workspaces/{workspace}/incidents/{incident}/updates/{update}', {
      params: {
        path: { workspace: slug.value, incident: incidentId.value, update: entry.id },
      },
      body: { message: editMessage.value },
    }))
    editing.value = null
    notify(t('incidents.updateEdit.saved'))
    apply(await fetchIncident())
  }
  catch (e) {
    editError.value = failure(e)
  }
  finally {
    editBusy.value = false
  }
}

const removingUpdate = ref<IncidentTimelineEntry | null>(null)
const removeUpdateError = ref<ApiError | null>(null)
const removeUpdateBusy = ref(false)
const removeUpdateOpen = computed({
  get: () => removingUpdate.value !== null,
  set: (open) => {
    if (!open) {
      removingUpdate.value = null
      removeUpdateError.value = null
    }
  },
})

async function removeUpdate(): Promise<void> {
  const entry = removingUpdate.value
  if (!entry || removeUpdateBusy.value)
    return
  removeUpdateBusy.value = true
  removeUpdateError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/incidents/{incident}/updates/{update}', {
      params: {
        path: { workspace: slug.value, incident: incidentId.value, update: entry.id },
      },
    }))
    removingUpdate.value = null
    notify(t('incidents.updateDelete.deleted'))
    apply(await fetchIncident())
  }
  catch (e) {
    removeUpdateError.value = failure(e)
  }
  finally {
    removeUpdateBusy.value = false
  }
}

// Deleting the incident.
const removing = ref(false)
const removeError = ref<ApiError | null>(null)
const removeBusy = ref(false)

async function removeIncident(): Promise<void> {
  if (removeBusy.value)
    return
  removeBusy.value = true
  removeError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/incidents/{incident}', {
      params: {
        path: { workspace: slug.value, incident: incidentId.value },
      },
    }))
    leave.allow()
    notify(t('incidents.delete.deleted'))
    await router.push({
      name: 'ws.incidents',
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
  <v-progress-linear v-if="loading" indeterminate :aria-label="t('incidents.loading')" />
  <div v-else-if="loadError">
    <ProblemAlert :error="loadError" />
    <v-btn variant="tonal" data-test="retry" @click="load">
      {{ t('incidents.retry') }}
    </v-btn>
  </div>

  <template v-else-if="incident && details">
    <div class="mb-2">
      <router-link :to="{ name: 'ws.incidents', params: { workspace: slug } }">
        {{ t('incidents.back') }}
      </router-link>
    </div>
    <div class="d-flex align-center flex-wrap ga-2 mb-2">
      <h1 class="text-h5">
        {{ incident.title }}
      </h1>
      <ImpactChip :impact="incident.impact" />
      <span data-test="incident-status">
        <IncidentStatusChip :status="incident.status" />
      </span>
      <v-spacer />
      <v-btn
        v-if="canManage"
        data-test="incident-delete"
        color="error"
        variant="outlined"
        :prepend-icon="mdiTrashCanOutline"
        @click="removing = true"
      >
        {{ t('common.delete') }}
      </v-btn>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ t('incidents.fields.started') }}: <DateTime :value="incident.started_at" />
      <template v-if="incident.resolved_at">
        · {{ t('incidents.fields.resolved') }}: <DateTime :value="incident.resolved_at" />
      </template>
    </p>

    <v-alert v-if="!canManage" type="info" variant="tonal" class="mb-4">
      {{ t('incidents.readOnly') }}
    </v-alert>

    <v-row>
      <v-col cols="12" md="7">
        <v-card class="mb-4" max-width="860">
          <v-card-title tag="h2" class="text-h6">
            {{ t('incidents.timeline.title') }}
          </v-card-title>
          <v-card-text>
            <UpdateTimeline :entries="entries" :editable="canManage" @edit="startEdit" @remove="removingUpdate = $event" />
          </v-card-text>
        </v-card>

        <v-card v-if="canManage" max-width="860">
          <v-card-title tag="h2" class="text-h6">
            {{ t('incidents.composer.title') }}
          </v-card-title>
          <v-card-text>
            <UpdateComposer
              ref="composer"
              :current="incident.status"
              :busy="posting"
              :error="postError"
              @post="post"
            />
            <p class="text-caption text-medium-emphasis mt-3">
              {{ t('incidents.staleNote') }}
            </p>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="5">
        <v-card max-width="860">
          <v-form :disabled="!canManage" @submit.prevent="saveDetails">
            <v-card-title tag="h2" class="text-h6">
              {{ t('incidents.details.title') }}
            </v-card-title>
            <v-card-text>
              <ProblemAlert :error="saveError" :known-fields="detailKnown" />
              <v-text-field
                v-model="details.title"
                name="incident_title"
                :label="`${t('incidents.fields.title')} *`"
                maxlength="190"
                autocomplete="off"
                :error-messages="detailFields.title"
              />
              <v-select
                v-model="details.impact"
                data-test="incident-impact"
                name="incident_impact"
                :items="impactItems"
                :label="t('incidents.fields.impact')"
                :error-messages="detailFields.impact"
              />
              <AffectedPicker
                v-model:monitor-ids="details.monitor_ids"
                v-model:status-page-ids="details.status_page_ids"
                :slug="slug"
                :disabled="!canManage"
                :can-list-monitors="can('viewMonitors')"
                :monitors-error="detailFields.monitor_ids"
                :status-pages-error="detailFields.status_page_ids"
              />
            </v-card-text>
            <v-card-actions v-if="canManage">
              <v-spacer />
              <v-btn
                type="submit"
                color="primary"
                variant="flat"
                data-test="incident-save"
                :loading="saving"
                :disabled="!dirty"
              >
                {{ t('incidents.details.save') }}
              </v-btn>
            </v-card-actions>
          </v-form>
        </v-card>
      </v-col>
    </v-row>
  </template>

  <ConfirmDialog
    v-model="editOpen"
    :title="t('incidents.updateEdit.title')"
    :confirm-label="t('common.save')"
    :loading="editBusy"
    :confirm-disabled="editMessage.trim() === ''"
    @confirm="saveEdit"
  >
    <ProblemAlert :error="editError" />
    <p class="mb-2">
      {{ t('incidents.updateEdit.text') }}
    </p>
    <v-textarea
      v-model="editMessage"
      name="edit_message"
      :label="t('incidents.fields.message')"
      rows="4"
      maxlength="10000"
    />
  </ConfirmDialog>

  <ConfirmDialog
    v-model="removeUpdateOpen"
    :title="t('incidents.updateDelete.title')"
    :confirm-label="t('common.delete')"
    :loading="removeUpdateBusy"
    destructive
    @confirm="removeUpdate"
  >
    <ProblemAlert :error="removeUpdateError" />
    {{ t('incidents.updateDelete.text') }}
  </ConfirmDialog>

  <ConfirmDialog
    v-model="removing"
    :title="t('incidents.delete.title')"
    :confirm-label="t('common.delete')"
    :loading="removeBusy"
    destructive
    @confirm="removeIncident"
  >
    <ProblemAlert :error="removeError" />
    {{ t('incidents.delete.text', { title: incident?.title ?? '' }) }}
  </ConfirmDialog>

  <ConfirmDialog
    v-model="leave.open.value"
    :title="t('incidents.leave.title')"
    :text="t('incidents.leave.text')"
    :confirm-label="t('incidents.leave.confirm')"
    destructive
    @confirm="leave.confirm"
  />
</template>
