<script setup lang="ts">
import type { ApiError } from '../../api'
import type { ChannelSummary, ChannelType, ChannelTypeInfo, Monitor } from '../../api/types'
import type { SecretDraft } from '../../components/monitors/forms/model'
import type { ChannelForm } from '../../components/notifications/model'
import type { TestResult } from '../../components/notifications/TestSendButton.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { BULK_MAX_IDS } from '../../components/monitors/filters'
import { clone, deepEqual, errorFor, invalidHeaderLines } from '../../components/monitors/forms/model'
import ChannelFields from '../../components/notifications/ChannelFields.vue'
import { CHANNEL_TYPES, configFields, renderedSecrets } from '../../components/notifications/fields'
import {
  createBody,
  formFromChannel,
  hasSecretDraft,
  newChannelForm,
  patchBody,
  pruneSecrets,
  withSmtpSecretCleared,
} from '../../components/notifications/model'
import TemplateFields from '../../components/notifications/TemplateFields.vue'
import TestSendButton from '../../components/notifications/TestSendButton.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'
import { asApiError } from '../../utils/errors'

/** How many monitors the binding picker loads per search. */
const MONITOR_OPTIONS = 20

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => session.current?.slug ?? String(route.params.workspace))
const channelId = computed(() => route.params.channel === undefined
  ? null
  : Number(route.params.channel))
const isEdit = computed(() => channelId.value !== null)
const canManage = computed(() => can('manageNotifications'))
const canBind = computed(() => can('manageMonitors') && can('viewMonitors'))

const infos = ref<ChannelTypeInfo[]>([])
const type = ref<ChannelType>('email')
const form = ref<ChannelForm | null>(null)
const initial = ref<ChannelForm | null>(null)
const secrets = ref<SecretDraft>({})
const secretKeys = ref<string[]>([])
const monitorCount = ref(0)
const loading = ref(true)
const loadError = ref<ApiError | null>(null)
const saving = ref(false)
const error = ref<ApiError | null>(null)
const clientSecretErrors = ref<Record<string, string>>({})
const testing = ref(false)
const testResult = ref<TestResult | null>(null)

const info = computed(() => infos.value.find(i => i.type === type.value))
const typeItems = computed(() => CHANNEL_TYPES
  .filter(v => infos.value.some(i => i.type === v))
  .map(v => ({
    value: v,
    title: t(`channels.types.${v}`),
  })))

const fields = computed(() => fieldErrors(error.value))
const configErrors = computed(() => fieldErrors(error.value, 'config'))
const templateErrors = computed(() => fieldErrors(error.value, 'templates'))
const secretErrors = computed(() => {
  const all = {
    ...fieldErrors(error.value, 'secrets'),
    ...clientSecretErrors.value,
  }
  const out: Record<string, string> = {}
  for (const spec of renderedSecrets(type.value, form.value?.config ?? {})) {
    const message = errorFor(all, spec.key)
    if (message !== undefined)
      out[spec.key] = message
  }
  return out
})

/** Error roots of the controls that are on screen right now; every other key goes to the alert. */
const knownFields = computed(() => {
  const current = form.value
  const roots = [
    'name',
    'active',
    'is_default',
    'templates.title',
    'templates.body',
    ...configFields(type.value).map(f => `config.${f.key}`),
    ...renderedSecrets(type.value, current?.config ?? {}).map(f => `secrets.${f.key}`),
  ]
  if (!isEdit.value)
    roots.push('type')
  if (current?.is_default)
    roots.push('apply_to_existing')
  return Object.keys(error.value?.errors ?? {}).filter(key =>
    roots.some(root => key === root || key.startsWith(`${root}.`)))
})

const dirty = computed(() => form.value !== null
  && initial.value !== null
  && (!deepEqual(form.value, initial.value) || hasSecretDraft(secrets.value)))

function startCreate(next: ChannelType): void {
  const entry = infos.value.find(i => i.type === next)
  if (!entry)
    return
  type.value = next
  const fresh = newChannelForm(entry)
  const previous = form.value
  initial.value = clone(fresh)
  // Switching type keeps what the user already entered for the shared fields.
  form.value = previous === null
    ? fresh
    : {
        ...fresh,
        name: previous.name,
        active: previous.active,
        is_default: previous.is_default,
        apply_to_existing: previous.apply_to_existing,
        templates: previous.templates,
      }
  secrets.value = {}
  secretKeys.value = []
  error.value = null
  testResult.value = null
}

function applyChannel(channel: ChannelSummary): void {
  type.value = channel.type
  form.value = formFromChannel(channel)
  initial.value = clone(form.value)
  secrets.value = {}
  secretKeys.value = [...channel.secret_keys]
  monitorCount.value = channel.monitor_count
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    infos.value = await bodyOf(api.GET('/notification-channel-types'))
    if (channelId.value === null) {
      startCreate(typeItems.value[0]?.value ?? 'email')
    }
    else {
      applyChannel(await unwrap<ChannelSummary>(api.GET('/workspaces/{workspace}/notification-channels/{channel}', {
        params: {
          path: { workspace: slug.value, channel: channelId.value },
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

// A viewer is sent back to the list; the API still refuses writes on its own.
if (!canManage.value) {
  void router.replace({
    name: 'ws.channels',
    params: { workspace: slug.value },
  })
}
else {
  onMounted(load)
  watch(channelId, (next, previous) => {
    if (next !== previous) {
      form.value = null
      void load()
    }
  })
}

function onTypeChange(next: ChannelType): void {
  if (!isEdit.value && next !== type.value)
    startCreate(next)
}

/** The secrets to send, or null when a client-side check failed. */
function checkedSecrets(current: ChannelForm): SecretDraft | null {
  clientSecretErrors.value = {}
  const pruned = pruneSecrets(type.value, current.config, secrets.value)
  const draft = isEdit.value && initial.value !== null
    ? withSmtpSecretCleared(type.value, initial.value.config, current.config, secretKeys.value, pruned)
    : pruned
  const headerDraft = draft.secret_headers
  if (typeof headerDraft === 'string') {
    const bad = invalidHeaderLines(headerDraft)
    if (bad.length > 0) {
      clientSecretErrors.value = {
        secret_headers: t('channels.mapInvalid', {
          lines: bad.join(', '),
        }),
      }
      return null
    }
  }
  return draft
}

// Set just before navigating away after a successful create so the leave guard stays quiet.
let saved = false

async function save(): Promise<void> {
  const current = form.value
  const baseline = initial.value
  if (!current || !baseline || saving.value || !canManage.value)
    return
  error.value = null
  const draft = checkedSecrets(current)
  if (draft === null)
    return
  const id = channelId.value
  const update = isEdit.value && id !== null
    ? patchBody(baseline, current, draft)
    : null
  if (update !== null && Object.keys(update).length === 0) {
    notify(t('channels.noChanges'), 'info')
    return
  }

  saving.value = true
  try {
    if (update !== null && id !== null) {
      const result = await unwrap<ChannelSummary>(api.PATCH('/workspaces/{workspace}/notification-channels/{channel}', {
        params: {
          path: { workspace: slug.value, channel: id },
        },
        body: update,
      }))
      applyChannel(result)
      testResult.value = null
      notify(t('channels.updated'))
    }
    else {
      const result = await unwrap<ChannelSummary>(api.POST('/workspaces/{workspace}/notification-channels', {
        params: {
          path: { workspace: slug.value },
        },
        body: createBody(type.value, current, draft),
      }))
      secrets.value = {}
      saved = true
      notify(t('channels.created'))
      await router.push({
        name: 'ws.channel',
        params: {
          workspace: slug.value,
          channel: String(result.id),
        },
      })
      // The editor now shows the saved channel, so later edits are guarded again.
      saved = false
    }
  }
  catch (e) {
    saved = false
    error.value = failure(e)
  }
  finally {
    saving.value = false
  }
}

const testNote = computed(() => isEdit.value && dirty.value
  ? t('channels.test.saveFirst')
  : undefined)

async function sendTest(): Promise<void> {
  const current = form.value
  if (!current || testing.value)
    return
  error.value = null
  testResult.value = null
  testing.value = true
  try {
    if (isEdit.value && channelId.value !== null) {
      testResult.value = await unwrap<TestResult>(api.POST('/workspaces/{workspace}/notification-channels/{channel}/test', {
        params: {
          path: { workspace: slug.value, channel: channelId.value },
        },
      }))
      return
    }
    const draft = checkedSecrets(current)
    if (draft === null)
      return
    testResult.value = await unwrap<TestResult>(api.POST('/workspaces/{workspace}/notification-channels/test', {
      params: {
        path: { workspace: slug.value },
      },
      body: createBody(type.value, current, draft),
    }))
  }
  catch (e) {
    error.value = failure(e)
  }
  finally {
    testing.value = false
  }
}

// Monitor binding: the API has no per-channel listing of bound monitors, so the picker applies a change.
const monitorOptions = ref<Array<Pick<Monitor, 'id' | 'name'> & { bound: boolean }>>([])
const monitorItems = computed(() => monitorOptions.value.map(m => ({
  id: m.id,
  name: m.bound
    ? `${m.name} (${t('channels.binding.bound')})`
    : m.name,
})))
const pickedMonitors = ref<number[]>([])
const monitorSearch = ref('')
const monitorsLoading = ref(false)
const binding = ref(false)
const bindError = ref<ApiError | null>(null)
let searchSeq = 0

async function searchMonitors(q: string): Promise<void> {
  const mine = ++searchSeq
  monitorsLoading.value = true
  try {
    const result = await bodyOf(api.GET('/workspaces/{workspace}/monitors', {
      params: {
        path: { workspace: slug.value },
        query: {
          q: q.trim() === ''
            ? undefined
            : q.trim(),
          limit: MONITOR_OPTIONS,
        },
      },
    }))
    if (mine !== searchSeq)
      return
    const channel = channelId.value
    const options = result.data.map(m => ({
      id: m.id,
      name: m.name,
      bound: channel !== null && m.notification_channel_ids.map(Number).includes(channel),
    }))
    // Picked monitors stay selectable even when the new search no longer lists them.
    const keep = monitorOptions.value.filter(m => pickedMonitors.value.includes(m.id) && !options.some(o => o.id === m.id))
    monitorOptions.value = [
      ...keep,
      ...options,
    ]
  }
  catch {
    // The picker stays usable with what it already has.
  }
  finally {
    if (mine === searchSeq)
      monitorsLoading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(monitorSearch, (q) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void searchMonitors(q), 250)
})

async function bind(action: 'notify' | 'unnotify'): Promise<void> {
  const id = channelId.value
  if (id === null || binding.value || pickedMonitors.value.length === 0)
    return
  binding.value = true
  bindError.value = null
  try {
    const result = await bodyOf(api.POST('/workspaces/{workspace}/monitors/bulk', {
      params: {
        path: { workspace: slug.value },
      },
      body: { action, ids: pickedMonitors.value, channel_id: id },
    }))
    notify(t(action === 'notify'
      ? 'channels.binding.subscribed'
      : 'channels.binding.unsubscribed', { count: result.affected }))
    pickedMonitors.value = []
    void refreshBinding(id)
  }
  catch (e) {
    bindError.value = failure(e)
  }
  finally {
    binding.value = false
  }
}

/** Updates the subscribed count and the markers; the change itself already succeeded, so failures stay quiet. */
async function refreshBinding(id: number): Promise<void> {
  try {
    const fresh = await unwrap<ChannelSummary>(api.GET('/workspaces/{workspace}/notification-channels/{channel}', {
      params: {
        path: { workspace: slug.value, channel: id },
      },
    }))
    monitorCount.value = fresh.monitor_count
  }
  catch {
    // The count refreshes on the next visit.
  }
  await searchMonitors(monitorSearch.value)
}

function cancel(): void {
  void router.push({
    name: 'ws.channels',
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
  <template v-if="canManage">
    <h1 class="text-h5 mb-4">
      {{ isEdit ? t('channels.editTitle') : t('channels.newTitle') }}
    </h1>

    <v-progress-linear v-if="loading" indeterminate :aria-label="t('channels.loading')" />
    <div v-else-if="loadError">
      <ProblemAlert :error="loadError" />
      <v-btn variant="tonal" data-test="retry" @click="load">
        {{ t('channels.retry') }}
      </v-btn>
    </div>

    <template v-else-if="form && info">
      <v-card max-width="860" class="mb-4">
        <v-form @submit.prevent="save">
          <v-card-text>
            <ProblemAlert :error="error" :known-fields="knownFields" />

            <v-select
              :model-value="type"
              data-test="channel-type"
              name="channel_type"
              :items="typeItems"
              :label="t('channels.fields.type')"
              :hint="isEdit ? t('channels.typeLocked') : undefined"
              :persistent-hint="isEdit"
              :disabled="isEdit"
              :error-messages="fields.type"
              @update:model-value="onTypeChange"
            />
            <v-text-field
              v-model="form.name"
              name="channel_name"
              :label="`${t('channels.fields.name')} *`"
              maxlength="120"
              :error-messages="fields.name"
              autocomplete="off"
            />
            <p v-if="!info.supports_digest" class="text-caption text-medium-emphasis mb-4">
              {{ t('channels.noDigest') }}
            </p>

            <h2 class="text-h6 mt-4 mb-2">
              {{ t('channels.sections.settings', { type: t(`channels.types.${type}`) }) }}
            </h2>
            <ChannelFields
              :key="`${type}-${channelId ?? 'new'}`"
              v-model:config="form.config"
              v-model:secrets="secrets"
              :type="type"
              :errors="configErrors"
              :secret-keys="secretKeys"
              :secret-errors="secretErrors"
            />

            <h2 class="text-h6 mt-4 mb-2">
              {{ t('channels.sections.delivery') }}
            </h2>
            <v-switch
              v-model="form.active"
              name="channel_active"
              :label="t('channels.fields.active')"
              :hint="t('channels.activeHint')"
              persistent-hint
              color="primary"
              inset
              :error-messages="fields.active"
            />
            <v-switch
              v-model="form.is_default"
              name="channel_is_default"
              :label="t('channels.fields.is_default')"
              :hint="t('channels.defaultHint')"
              persistent-hint
              color="primary"
              inset
              :error-messages="fields.is_default"
            />
            <v-switch
              v-if="form.is_default"
              v-model="form.apply_to_existing"
              name="channel_apply_to_existing"
              :label="t('channels.fields.apply_to_existing')"
              :hint="t('channels.applyHint')"
              persistent-hint
              color="primary"
              inset
              :error-messages="fields.apply_to_existing"
            />

            <h2 class="text-h6 mt-4 mb-2">
              {{ t('channels.sections.templates') }}
            </h2>
            <TemplateFields v-model="form.templates" :errors="templateErrors" />

            <h2 class="text-h6 mt-4 mb-2">
              {{ t('channels.sections.test') }}
            </h2>
            <TestSendButton
              :loading="testing"
              :disabled="isEdit && dirty"
              :note="testNote"
              :result="testResult"
              @test="sendTest"
            />
          </v-card-text>
          <v-card-actions>
            <v-spacer />
            <v-btn variant="text" data-test="cancel" @click="cancel">
              {{ t('common.cancel') }}
            </v-btn>
            <v-btn
              type="submit"
              color="primary"
              variant="flat"
              data-test="channel-save"
              :loading="saving"
            >
              {{ isEdit ? t('common.save') : t('common.create') }}
            </v-btn>
          </v-card-actions>
        </v-form>
      </v-card>

      <v-card v-if="isEdit && canBind" max-width="860" data-test="binding-card">
        <v-card-title as="h2" class="text-h6">
          {{ t('channels.binding.title') }}
        </v-card-title>
        <v-card-text>
          <p class="mb-2">
            {{ t('channels.binding.count', { count: monitorCount }) }}
          </p>
          <ProblemAlert :error="bindError" />
          <v-autocomplete
            v-model="pickedMonitors"
            v-model:search="monitorSearch"
            data-test="binding-monitors"
            name="binding_monitors"
            :items="monitorItems"
            item-title="name"
            item-value="id"
            :loading="monitorsLoading"
            :label="t('channels.binding.monitors')"
            :hint="t('channels.binding.hint', { max: BULK_MAX_IDS })"
            persistent-hint
            multiple
            chips
            closable-chips
            no-filter
            @focus="searchMonitors(monitorSearch)"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            data-test="binding-unnotify"
            variant="outlined"
            :loading="binding"
            :disabled="pickedMonitors.length === 0 || pickedMonitors.length > BULK_MAX_IDS"
            @click="bind('unnotify')"
          >
            {{ t('channels.binding.unsubscribe') }}
          </v-btn>
          <v-btn
            data-test="binding-notify"
            color="primary"
            variant="flat"
            :loading="binding"
            :disabled="pickedMonitors.length === 0 || pickedMonitors.length > BULK_MAX_IDS"
            @click="bind('notify')"
          >
            {{ t('channels.binding.subscribe') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </template>

    <ConfirmDialog
      v-model="leaveOpen"
      :title="t('channels.leave.title')"
      :text="t('channels.leave.text')"
      :confirm-label="t('channels.leave.confirm')"
      destructive
      @confirm="confirmLeave"
    />
  </template>
</template>
