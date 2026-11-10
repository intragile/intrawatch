<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import HelpLink from '../../components/help/HelpLink.vue'
import { screenHelp } from '../../components/help/manual'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useSession } from '../../stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSession()
const can = useCan()
const { notify } = useNotify()
const failure = useApiFailure()

const workspace = computed(() => session.current)
const name = ref('')
const timezone = ref('')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const fields = computed(() => fieldErrors(error.value))

const confirmDelete = ref(false)
const confirmName = ref('')
const deleteError = ref<ApiError | null>(null)

/** IANA zones known to the browser; the workspace's own zone is always selectable. */
const timezones = computed(() => {
  const zones: string[] = typeof Intl.supportedValuesOf === 'function'
    ? Intl.supportedValuesOf('timeZone')
    : []
  const own = workspace.value?.timezone
  return own && !zones.includes(own)
    ? [own, ...zones]
    : zones
})

const nameMatches = computed(() => confirmName.value.trim() === workspace.value?.name)

function syncForm(): void {
  name.value = workspace.value?.name ?? ''
  timezone.value = workspace.value?.timezone ?? ''
}

watch(() => workspace.value?.id, () => {
  syncForm()
  error.value = null
}, { immediate: true })

watch(confirmDelete, (isOpen) => {
  if (isOpen) {
    confirmName.value = ''
    deleteError.value = null
  }
})

async function save(): Promise<void> {
  const current = workspace.value
  if (!current || busy.value || !can('update'))
    return
  busy.value = true
  error.value = null
  try {
    await unwrap(api.PATCH('/workspaces/{workspace}', {
      params: {
        path: { workspace: current.slug },
      },
      body: {
        name: name.value.trim(),
        timezone: timezone.value,
      },
    }))
    await session.load()
    syncForm()
    notify(t('settings.saved'))
  }
  catch (e) {
    error.value = failure(e)
  }
  finally {
    busy.value = false
  }
}

async function remove(): Promise<void> {
  const current = workspace.value
  if (!current || busy.value || !nameMatches.value)
    return
  busy.value = true
  deleteError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}', {
      params: {
        path: { workspace: current.slug },
      },
    }))
    confirmDelete.value = false
    await session.load()
    notify(t('settings.danger.deleted'))
    await router.push({ name: 'home' })
  }
  catch (e) {
    deleteError.value = failure(e)
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="d-flex align-center ga-2 mb-4">
    <h1 class="text-h5">
      {{ t('settings.title') }}
    </h1>
    <HelpLink v-bind="screenHelp('ws.settings')" />
  </div>

  <v-card v-if="workspace" class="mb-4" max-width="720">
    <v-form @submit.prevent="save">
      <v-card-text>
        <ProblemAlert :error="error" :known-fields="['name', 'timezone']" />
        <v-alert v-if="!can('update')" type="info" variant="tonal" class="mb-4">
          {{ t('settings.readOnly') }}
        </v-alert>
        <v-text-field
          v-model="name"
          name="settings_name"
          :label="t('settings.name')"
          :readonly="!can('update')"
          :error-messages="fields.name"
          maxlength="120"
        />
        <v-text-field
          :model-value="workspace.slug"
          name="settings_slug"
          :label="t('settings.slug')"
          readonly
        />
        <v-autocomplete
          v-model="timezone"
          name="settings_timezone"
          :items="timezones"
          :label="t('settings.timezone')"
          :readonly="!can('update')"
          :error-messages="fields.timezone"
        />
      </v-card-text>
      <v-card-actions v-if="can('update')">
        <v-spacer />
        <v-btn type="submit" color="primary" variant="flat" :loading="busy">
          {{ t('common.save') }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </v-card>

  <v-card v-if="workspace && can('delete')" max-width="720" border="error md">
    <v-card-title tag="h2" class="text-h6">
      {{ t('settings.danger.title') }}
    </v-card-title>
    <v-card-text>
      {{ t('settings.danger.text') }}
    </v-card-text>
    <v-card-actions>
      <v-spacer />
      <v-btn data-test="delete-workspace" color="error" variant="tonal" @click="confirmDelete = true">
        {{ t('settings.danger.action') }}
      </v-btn>
    </v-card-actions>
  </v-card>

  <ConfirmDialog
    v-model="confirmDelete"
    :title="t('settings.danger.title')"
    :confirm-label="t('common.delete')"
    :confirm-disabled="!nameMatches"
    :loading="busy"
    destructive
    @confirm="remove"
  >
    <ProblemAlert :error="deleteError" />
    <p class="mb-4">
      {{ t('settings.danger.confirmText', { name: workspace?.name ?? '' }) }}
    </p>
    <v-text-field
      v-model="confirmName"
      name="confirm_name"
      :label="t('settings.danger.confirmField')"
      autocomplete="off"
      autofocus
      @keydown.enter.prevent="remove"
    />
  </ConfirmDialog>
</template>
