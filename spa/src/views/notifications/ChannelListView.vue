<script setup lang="ts">
import type { ApiError } from '../../api'
import type { ChannelSummary } from '../../api/types'
import { mdiBellOff, mdiCheckCircle, mdiCloseCircle, mdiPencil, mdiPlus, mdiTrashCanOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, unwrap, useCursorList } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { bodyOf } from '../../utils/cursorPage'

const { t } = useI18n()
const route = useRoute()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => String(route.params.workspace))
const canManage = computed(() => can('manageNotifications'))

const list = useCursorList<ChannelSummary>(cursor => bodyOf(api.GET('/workspaces/{workspace}/notification-channels', {
  params: {
    path: { workspace: slug.value },
    query: { cursor: cursor ?? undefined },
  },
})))

watch(slug, () => {
  void list.reload()
})
void list.loadMore()

const removing = ref<ChannelSummary | null>(null)
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
  const channel = removing.value
  if (!channel || removeBusy.value)
    return
  removeBusy.value = true
  removeError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/notification-channels/{channel}', {
      params: {
        path: { workspace: slug.value, channel: channel.id },
      },
    }))
    removing.value = null
    notify(t('channels.deleted'))
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
    <h1 class="text-h5 flex-grow-1">
      {{ t('channels.title') }}
    </h1>
    <v-btn
      v-if="canManage"
      data-test="channel-new"
      color="primary"
      variant="flat"
      :prepend-icon="mdiPlus"
      :to="{ name: 'ws.channel.new', params: { workspace: slug } }"
    >
      {{ t('channels.new') }}
    </v-btn>
  </div>

  <ProblemAlert :error="list.error.value" />

  <v-table v-if="list.items.value.length > 0">
    <caption class="d-sr-only">
      {{ t('channels.title') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('channels.columns.name') }}
        </th>
        <th scope="col">
          {{ t('channels.columns.type') }}
        </th>
        <th scope="col">
          {{ t('channels.columns.state') }}
        </th>
        <th scope="col">
          {{ t('channels.columns.monitors') }}
        </th>
        <th scope="col">
          {{ t('channels.columns.delivery') }}
        </th>
        <th scope="col">
          <span class="d-sr-only">{{ t('channels.columns.actions') }}</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="channel in list.items.value" :key="channel.id" :data-test="`channel-row-${channel.id}`">
        <td>
          <router-link :to="{ name: 'ws.channel', params: { workspace: slug, channel: String(channel.id) } }">
            {{ channel.name }}
          </router-link>
        </td>
        <td>{{ t(`channels.types.${channel.type}`) }}</td>
        <td>
          <v-chip v-if="!channel.active" size="x-small" label :prepend-icon="mdiBellOff">
            {{ t('channels.inactive') }}
          </v-chip>
          <v-chip v-else size="x-small" label color="success" :prepend-icon="mdiCheckCircle">
            {{ t('channels.active') }}
          </v-chip>
          <v-chip v-if="channel.is_default" size="x-small" label class="ml-1">
            {{ t('channels.default') }}
          </v-chip>
        </td>
        <td>{{ channel.monitor_count }}</td>
        <td>
          <div v-if="channel.last_failure_at && (!channel.last_success_at || channel.last_failure_at > channel.last_success_at)" class="text-error">
            <v-icon :icon="mdiCloseCircle" size="small" />
            {{ t('channels.lastFailure') }} <DateTime :value="channel.last_failure_at" />
            <div v-if="channel.last_error" class="text-caption">
              {{ channel.last_error }}
            </div>
          </div>
          <div v-else-if="channel.last_success_at">
            <v-icon :icon="mdiCheckCircle" size="small" />
            {{ t('channels.lastSuccess') }} <DateTime :value="channel.last_success_at" />
          </div>
          <span v-else class="text-medium-emphasis">{{ t('channels.neverUsed') }}</span>
        </td>
        <td class="text-no-wrap text-right">
          <v-btn
            v-if="canManage"
            icon
            variant="text"
            size="small"
            :aria-label="`${t('common.edit')}: ${channel.name}`"
            :to="{ name: 'ws.channel', params: { workspace: slug, channel: String(channel.id) } }"
          >
            <v-icon :icon="mdiPencil" />
          </v-btn>
          <v-btn
            v-if="canManage"
            icon
            variant="text"
            size="small"
            color="error"
            :data-test="`channel-delete-${channel.id}`"
            :aria-label="`${t('common.delete')}: ${channel.name}`"
            @click="removing = channel"
          >
            <v-icon :icon="mdiTrashCanOutline" />
          </v-btn>
        </td>
      </tr>
    </tbody>
  </v-table>
  <EmptyState
    v-else-if="!list.loading.value && list.error.value === null"
    :icon="mdiBellOff"
    :title="t('channels.empty.title')"
    :text="t('channels.empty.text')"
  />
  <div class="d-flex justify-center mt-2">
    <v-btn v-if="list.hasMore.value && list.items.value.length > 0" :loading="list.loading.value" variant="text" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>

  <ConfirmDialog
    v-model="confirmRemove"
    :title="t('channels.delete.title')"
    :confirm-label="t('common.delete')"
    :loading="removeBusy"
    destructive
    @confirm="remove"
  >
    <ProblemAlert :error="removeError" />
    {{ t('channels.delete.text', { name: removing?.name ?? '' }) }}
  </ConfirmDialog>
</template>
