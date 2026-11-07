<script setup lang="ts">
import type { ApiError } from '../../api'
import type { StatusPageSummary } from '../../api/types'
import { mdiMonitorDashboard, mdiOpenInNew, mdiPencil, mdiPlus, mdiTrashCanOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, unwrap, useCursorList } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useRefetchOnReturn } from '../../composables/useRefetchOnReturn'
import { bodyOf } from '../../utils/cursorPage'

const { t } = useI18n()
const route = useRoute()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => String(route.params.workspace))
const canManage = computed(() => can('manageStatusPages'))

const list = useCursorList<StatusPageSummary>(cursor => bodyOf(api.GET('/workspaces/{workspace}/status-pages', {
  params: {
    path: { workspace: slug.value },
    query: { cursor: cursor ?? undefined },
  },
})))

watch(slug, () => void list.reload())
void list.loadMore()
useRefetchOnReturn(() => void list.reloadLoaded(), () => list.loading.value)

const removing = ref<StatusPageSummary | null>(null)
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
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/status-pages/{statusPage}', {
      params: {
        path: { workspace: slug.value, statusPage: target.id },
      },
    }))
    removing.value = null
    notify(t('statusPages.delete.deleted'))
    await list.reloadLoaded()
  }
  catch (e) {
    removeError.value = failure(e)
  }
  finally {
    removeBusy.value = false
  }
}

function editRoute(id: number | string) {
  return {
    name: 'ws.status-page',
    params: {
      workspace: slug.value,
      statusPage: String(id),
    },
  }
}
</script>

<template>
  <div class="d-flex align-center flex-wrap ga-2 mb-4">
    <h1 class="text-h5 flex-grow-1">
      {{ t('statusPages.title') }}
    </h1>
    <v-btn
      v-if="canManage"
      data-test="status-page-new"
      color="primary"
      variant="flat"
      :prepend-icon="mdiPlus"
      :to="editRoute('new')"
    >
      {{ t('statusPages.new') }}
    </v-btn>
  </div>

  <ProblemAlert :error="list.error.value" />

  <v-table v-if="list.items.value.length > 0">
    <caption class="d-sr-only">
      {{ t('statusPages.title') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('statusPages.columns.title') }}
        </th>
        <th scope="col">
          {{ t('statusPages.columns.address') }}
        </th>
        <th scope="col">
          {{ t('statusPages.columns.state') }}
        </th>
        <th scope="col">
          <span class="d-sr-only">{{ t('statusPages.columns.actions') }}</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="p in list.items.value" :key="p.id" :data-test="`status-page-row-${p.id}`">
        <td>
          <router-link :to="editRoute(p.id)">
            {{ p.title }}
          </router-link>
        </td>
        <td>
          <code>/status/{{ p.slug }}</code>
          <div v-if="p.custom_domain" class="text-caption text-medium-emphasis">
            {{ p.custom_domain }}
          </div>
        </td>
        <td>
          <v-chip size="small" :color="p.published ? 'success' : undefined" label>
            {{ p.published ? t('statusPages.state.published') : t('statusPages.state.draft') }}
          </v-chip>
        </td>
        <td class="text-no-wrap text-right">
          <v-btn
            icon
            variant="text"
            size="small"
            :href="`/status/${p.slug}`"
            target="_blank"
            rel="noopener"
            :aria-label="`${t('statusPages.openPublic')}: ${p.title}`"
          >
            <v-icon :icon="mdiOpenInNew" />
          </v-btn>
          <v-btn
            v-if="canManage"
            icon
            variant="text"
            size="small"
            :aria-label="`${t('common.edit')}: ${p.title}`"
            :to="editRoute(p.id)"
          >
            <v-icon :icon="mdiPencil" />
          </v-btn>
          <v-btn
            v-if="canManage"
            icon
            variant="text"
            size="small"
            color="error"
            :data-test="`status-page-delete-${p.id}`"
            :aria-label="`${t('common.delete')}: ${p.title}`"
            @click="removing = p"
          >
            <v-icon :icon="mdiTrashCanOutline" />
          </v-btn>
        </td>
      </tr>
    </tbody>
  </v-table>
  <EmptyState
    v-else-if="!list.loading.value && list.error.value === null"
    :icon="mdiMonitorDashboard"
    :title="t('statusPages.empty.title')"
    :text="t('statusPages.empty.text')"
  />
  <div class="d-flex justify-center mt-2">
    <v-btn v-if="list.hasMore.value && list.items.value.length > 0" :loading="list.loading.value" variant="text" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>

  <ConfirmDialog
    v-model="confirmRemove"
    :title="t('statusPages.delete.title')"
    :confirm-label="t('common.delete')"
    :loading="removeBusy"
    destructive
    @confirm="remove"
  >
    <ProblemAlert :error="removeError" />
    {{ t('statusPages.delete.text', { title: removing?.title ?? '' }) }}
  </ConfirmDialog>
</template>
