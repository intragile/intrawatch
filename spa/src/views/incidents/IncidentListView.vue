<script setup lang="ts">
import type { ApiError } from '../../api'
import type { IncidentCreateBody, IncidentSummary } from '../../api/types'
import { mdiPlus, mdiTextBoxCheckOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, unwrap, useCursorList } from '../../api'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import ImpactChip from '../../components/incidents/ImpactChip.vue'
import IncidentCreateDialog from '../../components/incidents/IncidentCreateDialog.vue'
import IncidentStatusChip from '../../components/incidents/IncidentStatusChip.vue'
import InternalChip from '../../components/incidents/InternalChip.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useRefetchOnReturn } from '../../composables/useRefetchOnReturn'
import { bodyOf } from '../../utils/cursorPage'

type StateFilter = 'all' | 'open' | 'resolved'
const FILTERS: StateFilter[] = ['all', 'open', 'resolved']

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => String(route.params.workspace))
const canManage = computed(() => can('manageIncidents'))
const state = ref<StateFilter>('open')

const list = useCursorList<IncidentSummary>(cursor => bodyOf(api.GET('/workspaces/{workspace}/incidents', {
  params: {
    path: { workspace: slug.value },
    query: {
      state: state.value === 'all'
        ? undefined
        : state.value,
      cursor: cursor ?? undefined,
    },
  },
})))

watch([slug, state], () => {
  void list.reload()
})
void list.loadMore()
useRefetchOnReturn(() => void list.reloadLoaded(), () => list.loading.value)

const creating = ref(false)
const createBusy = ref(false)
const createError = ref<ApiError | null>(null)

async function create(body: IncidentCreateBody): Promise<void> {
  if (createBusy.value)
    return
  createBusy.value = true
  createError.value = null
  try {
    const incident = await unwrap<IncidentSummary>(api.POST('/workspaces/{workspace}/incidents', {
      params: {
        path: { workspace: slug.value },
      },
      body,
    }))
    creating.value = false
    notify(t('incidents.create.created'))
    await router.push({
      name: 'ws.incident',
      params: {
        workspace: slug.value,
        incident: String(incident.id),
      },
    })
  }
  catch (e) {
    createError.value = failure(e)
  }
  finally {
    createBusy.value = false
  }
}
</script>

<template>
  <div class="d-flex align-center flex-wrap ga-2 mb-4">
    <h1 class="text-h5 flex-grow-1">
      {{ t('incidents.title') }}
    </h1>
    <v-btn
      v-if="canManage"
      data-test="incident-new"
      color="primary"
      variant="flat"
      :prepend-icon="mdiPlus"
      @click="creating = true"
    >
      {{ t('incidents.new') }}
    </v-btn>
  </div>

  <v-btn-toggle
    v-model="state"
    mandatory
    density="comfortable"
    variant="outlined"
    divided
    class="mb-4"
    :aria-label="t('incidents.filter.label')"
  >
    <v-btn v-for="f in FILTERS" :key="f" :value="f" :data-test="`state-${f}`">
      {{ t(`incidents.filter.${f}`) }}
    </v-btn>
  </v-btn-toggle>

  <ProblemAlert :error="list.error.value" />

  <v-table v-if="list.items.value.length > 0">
    <caption class="d-sr-only">
      {{ t('incidents.title') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('incidents.columns.title') }}
        </th>
        <th scope="col">
          {{ t('incidents.columns.impact') }}
        </th>
        <th scope="col">
          {{ t('incidents.columns.status') }}
        </th>
        <th scope="col">
          {{ t('incidents.columns.started') }}
        </th>
        <th scope="col">
          {{ t('incidents.columns.resolved') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="incident in list.items.value" :key="incident.id" :data-test="`incident-row-${incident.id}`">
        <td>
          <router-link :to="{ name: 'ws.incident', params: { workspace: slug, incident: String(incident.id) } }">
            {{ incident.title }}
          </router-link>
          <InternalChip v-if="incident.internal" size="x-small" class="ms-2" />
        </td>
        <td>
          <ImpactChip :impact="incident.impact" />
        </td>
        <td>
          <IncidentStatusChip :status="incident.status" />
        </td>
        <td><DateTime :value="incident.started_at" /></td>
        <td><DateTime :value="incident.resolved_at" /></td>
      </tr>
    </tbody>
  </v-table>
  <EmptyState
    v-else-if="!list.loading.value && list.error.value === null"
    :icon="mdiTextBoxCheckOutline"
    :title="t('incidents.empty.title')"
    :text="t('incidents.empty.text')"
  />
  <div class="d-flex justify-center mt-2">
    <v-btn v-if="list.hasMore.value && list.items.value.length > 0" :loading="list.loading.value" variant="text" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>

  <IncidentCreateDialog
    v-if="canManage"
    v-model="creating"
    :slug="slug"
    :busy="createBusy"
    :error="createError"
    @create="create"
  />
</template>
