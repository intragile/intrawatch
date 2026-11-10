<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Maintenance } from '../../api/types'
import { mdiCalendarRemove, mdiPencil, mdiPlus, mdiStopCircleOutline, mdiTrashCanOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, unwrap, useCursorList } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import HelpLink from '../../components/help/HelpLink.vue'
import { screenHelp } from '../../components/help/manual'
import StateChips from '../../components/maintenances/StateChips.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useRefetchOnReturn } from '../../composables/useRefetchOnReturn'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'

type StateFilter = 'all' | 'active' | 'upcoming' | 'past'
const FILTERS: StateFilter[] = ['all', 'active', 'upcoming', 'past']

const { t } = useI18n()
const route = useRoute()
const session = useSession()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => String(route.params.workspace))
const canManage = computed(() => can('manageIncidents'))
const zone = computed(() => session.current?.timezone ?? 'UTC')
const state = ref<StateFilter>('all')

const list = useCursorList<Maintenance>(cursor => bodyOf(api.GET('/workspaces/{workspace}/maintenances', {
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

/** Only a one-off maintenance can be ended; a recurring one is switched off in the editor. */
const endable = (m: Maintenance) => canManage.value && m.in_progress && m.strategy === 'once'

const ending = ref<Maintenance | null>(null)
const endError = ref<ApiError | null>(null)
const endBusy = ref(false)
const confirmEnd = computed({
  get: () => ending.value !== null,
  set: (open) => {
    if (!open) {
      ending.value = null
      endError.value = null
    }
  },
})

async function end(): Promise<void> {
  const target = ending.value
  if (!target || endBusy.value)
    return
  endBusy.value = true
  endError.value = null
  try {
    await unwrap<Maintenance>(api.POST('/workspaces/{workspace}/maintenances/{maintenance}/end', {
      params: {
        path: { workspace: slug.value, maintenance: target.id },
      },
    }))
    ending.value = null
    notify(t('maintenances.endNowDialog.ended'))
    await list.reloadLoaded()
  }
  catch (e) {
    endError.value = failure(e)
  }
  finally {
    endBusy.value = false
  }
}

const removing = ref<Maintenance | null>(null)
const removeError = ref<ApiError | null>(null)
const removeBusy = ref(false)
const confirmRemove = computed({
  get: () => removing.value !== null,
  set: (open) => {
    if (!open) {
      removing.value = null
      removeError.value = null
    }
  },
})

async function remove(): Promise<void> {
  const target = removing.value
  if (!target || removeBusy.value)
    return
  removeBusy.value = true
  removeError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/maintenances/{maintenance}', {
      params: {
        path: { workspace: slug.value, maintenance: target.id },
      },
    }))
    removing.value = null
    notify(t('maintenances.delete.deleted'))
    await list.reloadLoaded()
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
  <div class="d-flex align-center flex-wrap ga-2 mb-4">
    <h1 class="text-h5">
      {{ t('maintenances.title') }}
    </h1>
    <HelpLink v-bind="screenHelp('ws.maintenances')" />
    <v-spacer />
    <v-btn
      v-if="canManage"
      data-test="maintenance-new"
      color="primary"
      variant="flat"
      :prepend-icon="mdiPlus"
      :to="{ name: 'ws.maintenance', params: { workspace: slug, maintenance: 'new' } }"
    >
      {{ t('maintenances.new') }}
    </v-btn>
  </div>

  <v-btn-toggle
    v-model="state"
    mandatory
    density="comfortable"
    variant="outlined"
    divided
    class="mb-4"
    :aria-label="t('maintenances.filter.label')"
  >
    <v-btn v-for="f in FILTERS" :key="f" :value="f" :data-test="`state-${f}`">
      {{ t(`maintenances.filter.${f}`) }}
    </v-btn>
  </v-btn-toggle>

  <ProblemAlert :error="list.error.value" />

  <v-table v-if="list.items.value.length > 0">
    <caption class="d-sr-only">
      {{ t('maintenances.title') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('maintenances.columns.title') }}
        </th>
        <th scope="col">
          {{ t('maintenances.columns.strategy') }}
        </th>
        <th scope="col">
          {{ t('maintenances.columns.state') }}
        </th>
        <th scope="col">
          {{ t('maintenances.columns.window') }}
        </th>
        <th scope="col">
          {{ t('maintenances.columns.monitors') }}
        </th>
        <th scope="col">
          <span class="d-sr-only">{{ t('maintenances.columns.actions') }}</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="m in list.items.value" :key="m.id" :data-test="`maintenance-row-${m.id}`">
        <td>
          <router-link :to="{ name: 'ws.maintenance', params: { workspace: slug, maintenance: String(m.id) } }">
            {{ m.title }}
          </router-link>
        </td>
        <td>{{ t(`maintenances.strategy.${m.strategy}`) }}</td>
        <td>
          <StateChips :maintenance="m" />
        </td>
        <td>
          <template v-if="m.next_window">
            <DateTime :value="m.next_window.starts_at" :timezone="m.timezone ?? zone" />
            <template v-if="m.next_window.ends_at">
              –
              <DateTime :value="m.next_window.ends_at" :timezone="m.timezone ?? zone" />
            </template>
          </template>
          <span v-else class="text-medium-emphasis">—</span>
        </td>
        <td>{{ m.monitor_ids.length }}</td>
        <td class="text-no-wrap text-right">
          <v-btn
            v-if="endable(m)"
            size="small"
            variant="tonal"
            :prepend-icon="mdiStopCircleOutline"
            :data-test="`maintenance-end-${m.id}`"
            @click="ending = m"
          >
            {{ t('maintenances.endNow') }}
          </v-btn>
          <v-btn
            v-if="canManage"
            icon
            variant="text"
            size="small"
            :aria-label="`${t('common.edit')}: ${m.title}`"
            :to="{ name: 'ws.maintenance', params: { workspace: slug, maintenance: String(m.id) } }"
          >
            <v-icon :icon="mdiPencil" />
          </v-btn>
          <v-btn
            v-if="canManage"
            icon
            variant="text"
            size="small"
            color="error"
            :data-test="`maintenance-delete-${m.id}`"
            :aria-label="`${t('common.delete')}: ${m.title}`"
            @click="removing = m"
          >
            <v-icon :icon="mdiTrashCanOutline" />
          </v-btn>
        </td>
      </tr>
    </tbody>
  </v-table>
  <EmptyState
    v-else-if="!list.loading.value && list.error.value === null"
    :icon="mdiCalendarRemove"
    :title="t('maintenances.empty.title')"
    :text="t('maintenances.empty.text')"
  />
  <div class="d-flex justify-center mt-2">
    <v-btn v-if="list.hasMore.value && list.items.value.length > 0" :loading="list.loading.value" variant="text" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>

  <ConfirmDialog
    v-model="confirmEnd"
    :title="t('maintenances.endNowDialog.title')"
    :confirm-label="t('maintenances.endNowDialog.confirm')"
    :loading="endBusy"
    destructive
    @confirm="end"
  >
    <ProblemAlert :error="endError" />
    {{ t('maintenances.endNowDialog.text', { title: ending?.title ?? '' }) }}
  </ConfirmDialog>

  <ConfirmDialog
    v-model="confirmRemove"
    :title="t('maintenances.delete.title')"
    :confirm-label="t('common.delete')"
    :loading="removeBusy"
    destructive
    @confirm="remove"
  >
    <ProblemAlert :error="removeError" />
    {{ t('maintenances.delete.text', { title: removing?.title ?? '' }) }}
  </ConfirmDialog>
</template>
