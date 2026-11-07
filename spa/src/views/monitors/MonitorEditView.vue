<script setup lang="ts">
import type { ApiError } from '../../api'
import type { ChannelSummary, Monitor, MonitorType, MonitorTypeInfo, Tag } from '../../api/types'
import type { MonitorForm, SecretDraft } from '../../components/monitors/forms/model'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import AlertingFields from '../../components/monitors/forms/AlertingFields.vue'
import CommonFields from '../../components/monitors/forms/CommonFields.vue'
import {
  clone,
  createBody,
  deepEqual,
  formFromMonitor,
  invalidHeaderLines,
  newForm,
  patchBody,
  pruneSecrets,
  renderedSecrets,
} from '../../components/monitors/forms/model'
import NotificationPicker from '../../components/monitors/forms/NotificationPicker.vue'
import { FORM_KEYS, FORMS } from '../../components/monitors/forms/registry'
import TagPicker from '../../components/monitors/forms/TagPicker.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useMonitors } from '../../stores/monitors'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'
import { asApiError } from '../../utils/errors'

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
/** Page size and page cap when the tag and channel pickers load their options. */
const OPTION_PAGE = 200
const OPTION_PAGES = 5

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const monitors = useMonitors()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => session.current?.slug ?? '')
const monitorId = computed(() => route.params.monitor === undefined
  ? null
  : Number(route.params.monitor))
const isEdit = computed(() => monitorId.value !== null)
const canManage = computed(() => can('manageMonitors'))
const canPickChannels = computed(() => can('viewNotifications'))

const typeInfos = ref<MonitorTypeInfo[]>([])
const type = ref<MonitorType>('http')
const form = ref<MonitorForm | null>(null)
const initial = ref<MonitorForm | null>(null)
const secrets = ref<SecretDraft>({})
const secretKeys = ref<string[]>([])
const tags = ref<Tag[]>([])
const channels = ref<ChannelSummary[]>([])
const loading = ref(true)
const loadingOptions = ref(false)
const loadError = ref<ApiError | null>(null)
const saving = ref(false)
const error = ref<ApiError | null>(null)

const info = computed(() => typeInfos.value.find(i => i.type === type.value))
const fields = computed(() => fieldErrors(error.value))
const configErrors = computed(() => fieldErrors(error.value, 'config'))
const clientSecretErrors = ref<Record<string, string>>({})
const secretErrors = computed(() => ({
  ...fieldErrors(error.value, 'secrets'),
  ...clientSecretErrors.value,
}))
const alertingErrors = computed(() => fieldErrors(error.value, 'alerting'))
const tagErrors = computed(() => fieldErrors(error.value, 'tags'))
const channelError = computed(() => {
  const errors = fieldErrors(error.value)
  return errors.notification_channel_ids
    ?? Object.entries(errors).find(([k]) => k.startsWith('notification_channel_ids.'))?.[1]
})

/** Error roots of the controls that are on screen right now; every other key goes to the alert. */
const knownFields = computed(() => {
  const entry = info.value
  const current = form.value
  const keys = FORM_KEYS[type.value]
  const roots = [
    'name',
    'description',
    'active',
    'parent_id',
    'tags',
    'type',
    'alerting.resend_interval_minutes',
    'alerting.notify_changes',
    'alerting.notify_degraded',
    ...keys.config.map(k => `config.${k}`),
    ...renderedSecrets(type.value, current?.config ?? {}).map(k => `secrets.${k}`),
  ]
  if (canPickChannels.value)
    roots.push('notification_channel_ids')
  if (entry?.supports_inverted)
    roots.push('inverted')
  if (entry?.scheduled) {
    roots.push('interval_seconds', 'timeout_seconds', 'retries', 'retry_interval_seconds', 'degraded_threshold_ms')
  }
  if (current?.alerting.notify_degraded)
    roots.push('alerting.degraded_alert_after_seconds')
  return Object.keys(error.value?.errors ?? {}).filter(key =>
    roots.some(root => key === root || key.startsWith(`${root}.`)))
})

const dirty = computed(() => form.value !== null
  && initial.value !== null
  && (!deepEqual(form.value, initial.value)
    || Object.values(secrets.value).some(v => v !== undefined && v !== '')))

const FormComponent = computed(() => FORMS[type.value])

async function loadOptions(): Promise<void> {
  loadingOptions.value = true
  try {
    tags.value = await loadAll<Tag>(cursor => bodyOf(api.GET('/workspaces/{workspace}/tags', {
      params: {
        path: { workspace: slug.value },
        query: { limit: OPTION_PAGE, cursor },
      },
    })))
  }
  catch {
    tags.value = []
  }
  if (canPickChannels.value) {
    try {
      channels.value = await loadAll<ChannelSummary>(cursor => bodyOf(api.GET('/workspaces/{workspace}/notification-channels', {
        params: {
          path: { workspace: slug.value },
          query: { limit: OPTION_PAGE, cursor },
        },
      })))
    }
    catch {
      channels.value = []
    }
  }
  loadingOptions.value = false
}

async function loadAll<T>(fetchPage: (cursor: string | undefined) => Promise<{ data: T[], meta: { next_cursor: string | null } }>): Promise<T[]> {
  const out: T[] = []
  let cursor: string | undefined
  for (let i = 0; i < OPTION_PAGES; i++) {
    const page = await fetchPage(cursor)
    out.push(...page.data)
    if (page.meta.next_cursor === null)
      break
    cursor = page.meta.next_cursor
  }
  return out
}

function startCreate(next: MonitorType): void {
  const entry = typeInfos.value.find(i => i.type === next)
  if (!entry)
    return
  type.value = next
  const defaultChannels = canPickChannels.value
    ? channels.value.filter(c => c.is_default).map(c => c.id)
    : []
  const fresh = newForm(entry, defaultChannels)
  const previous = form.value
  initial.value = clone(fresh)
  // Switching type keeps what the user already entered for the shared fields.
  form.value = previous === null
    ? fresh
    : {
        ...fresh,
        name: previous.name,
        description: previous.description,
        parent_id: previous.parent_id,
        tags: previous.tags,
        notification_channel_ids: previous.notification_channel_ids,
        alerting: previous.alerting,
      }
  secrets.value = {}
  secretKeys.value = []
  error.value = null
}

function applyMonitor(monitor: Monitor): void {
  type.value = monitor.type
  form.value = formFromMonitor(monitor)
  initial.value = clone(form.value)
  secrets.value = {}
  secretKeys.value = [...monitor.secret_keys]
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    typeInfos.value = await bodyOf(api.GET('/monitor-types'))
    if (monitorId.value === null) {
      await loadOptions()
      startCreate('http')
    }
    else {
      const [monitor] = await Promise.all([
        unwrap<Monitor>(api.GET('/workspaces/{workspace}/monitors/{monitor}', {
          params: {
            path: { workspace: slug.value, monitor: monitorId.value },
          },
        })),
        loadOptions(),
      ])
      applyMonitor(monitor)
    }
  }
  catch (e) {
    loadError.value = asApiError(e)
  }
  finally {
    loading.value = false
  }
}

onMounted(load)
watch(monitorId, (next, previous) => {
  if (next !== previous)
    void load()
})

function onTypeChange(next: MonitorType): void {
  if (!isEdit.value && next !== type.value)
    startCreate(next)
}

// Set just before navigating away after a successful save so the leave guard stays quiet.
let saved = false

async function save(): Promise<void> {
  const current = form.value
  const baseline = initial.value
  const entry = info.value
  if (!current || !baseline || !entry || saving.value || !canManage.value)
    return
  if (entry.scheduled && current.interval_seconds < entry.min_interval_seconds)
    return

  error.value = null
  clientSecretErrors.value = {}
  const draft = pruneSecrets(type.value, current.config, secrets.value)
  const headerDraft = draft.secret_headers
  if (typeof headerDraft === 'string') {
    const bad = invalidHeaderLines(headerDraft)
    if (bad.length > 0) {
      clientSecretErrors.value = {
        secret_headers: t('monitorEditor.http.secretHeadersInvalid', {
          lines: bad.join(', '),
        }),
      }
      return
    }
  }
  const id = monitorId.value
  const update = isEdit.value && id !== null
    ? patchBody(baseline, current, draft)
    : null
  if (update !== null && Object.keys(update).length === 0) {
    notify(t('monitorEditor.noChanges'), 'info')
    return
  }

  saving.value = true
  try {
    const result = update !== null && id !== null
      ? await unwrap<Monitor>(api.PATCH('/workspaces/{workspace}/monitors/{monitor}', {
          params: {
            path: { workspace: slug.value, monitor: id },
          },
          body: update,
        }))
      : await unwrap<Monitor>(api.POST('/workspaces/{workspace}/monitors', {
          params: {
            path: { workspace: slug.value },
          },
          body: createBody(type.value, current, draft, canPickChannels.value),
        }))
    monitors.upsert(result)
    secrets.value = {}
    saved = true
    notify(t(isEdit.value ? 'monitorEditor.updated' : 'monitorEditor.created'))
    await router.push({
      name: 'ws.monitor',
      params: {
        workspace: slug.value,
        monitor: String(result.id),
      },
    })
  }
  catch (e) {
    saved = false
    error.value = failure(e)
  }
  finally {
    saving.value = false
  }
}

function cancel(): void {
  void router.push(isEdit.value && monitorId.value !== null
    ? {
        name: 'ws.monitor',
        params: {
          workspace: slug.value,
          monitor: String(monitorId.value),
        },
      }
    : {
        name: 'ws.monitors',
        params: { workspace: slug.value },
      })
}

// Leaving with edits asks first, through a dialog that resolves the pending navigation.
const leaveOpen = ref(false)
let settleLeave: ((allow: boolean) => void) | null = null

onBeforeRouteLeave(() => {
  if (saved || !dirty.value)
    return true
  leaveOpen.value = true
  return new Promise<boolean>((resolve) => {
    settleLeave = resolve
  })
})

function confirmLeave(): void {
  const settle = settleLeave
  settleLeave = null
  leaveOpen.value = false
  settle?.(true)
}

watch(leaveOpen, (open) => {
  if (!open && settleLeave) {
    const settle = settleLeave
    settleLeave = null
    settle(false)
  }
})
</script>

<template>
  <h1 class="text-h5 mb-4">
    {{ isEdit ? t('monitorEditor.editTitle') : t('monitorEditor.newTitle') }}
  </h1>

  <v-progress-linear v-if="loading" indeterminate :aria-label="t('monitorEditor.loading')" />
  <div v-else-if="loadError">
    <ProblemAlert :error="loadError" />
    <v-btn variant="tonal" data-test="retry" @click="load">
      {{ t('monitorEditor.retry') }}
    </v-btn>
  </div>

  <v-card v-else-if="form && info" max-width="860">
    <v-form :readonly="!canManage" @submit.prevent="save">
      <v-card-text>
        <ProblemAlert :error="error" :known-fields="knownFields" />
        <v-alert v-if="!canManage" type="info" variant="tonal" class="mb-4">
          {{ t('monitorEditor.readOnly') }}
        </v-alert>

        <v-select
          :model-value="type"
          data-test="monitor-type"
          name="monitor_type"
          :items="TYPES.map(v => ({ value: v, title: t(`monitors.types.${v}`) }))"
          :label="t('monitorEditor.common.type')"
          :hint="isEdit ? t('monitorEditor.common.typeLocked') : undefined"
          :persistent-hint="isEdit"
          :disabled="isEdit"
          :error-messages="fields.type"
          @update:model-value="onTypeChange"
        />

        <CommonFields
          v-model:form="form"
          :info="info"
          :errors="fields"
          :monitor-id="monitorId"
        />

        <h2 class="text-h6 mt-6 mb-2">
          {{ t('monitorEditor.sections.settings', { type: t(`monitors.types.${type}`) }) }}
        </h2>
        <component
          :is="FormComponent"
          :key="`${type}-${monitorId ?? 'new'}`"
          v-model:config="form.config"
          v-model:secrets="secrets"
          :errors="configErrors"
          :secret-keys="secretKeys"
          :secret-errors="secretErrors"
        />

        <h2 class="text-h6 mt-6 mb-2">
          {{ t('monitorEditor.sections.alerting') }}
        </h2>
        <AlertingFields v-model="form.alerting" :errors="alertingErrors" />

        <h2 class="text-h6 mt-6 mb-2">
          {{ t('monitorEditor.sections.organise') }}
        </h2>
        <NotificationPicker
          v-if="canPickChannels"
          v-model="form.notification_channel_ids"
          :channels="channels"
          :loading="loadingOptions"
          :error="channelError"
        />
        <TagPicker
          v-model="form.tags"
          :tags="tags"
          :loading="loadingOptions"
          :error="fields.tags"
          :errors="tagErrors"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="cancel" @click="cancel">
          {{ t('common.cancel') }}
        </v-btn>
        <v-btn
          v-if="canManage"
          type="submit"
          color="primary"
          variant="flat"
          data-test="save"
          :loading="saving"
        >
          {{ isEdit ? t('common.save') : t('common.create') }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </v-card>

  <ConfirmDialog
    v-model="leaveOpen"
    :title="t('monitorEditor.leave.title')"
    :text="t('monitorEditor.leave.text')"
    :confirm-label="t('monitorEditor.leave.confirm')"
    destructive
    @confirm="confirmLeave"
  />
</template>
