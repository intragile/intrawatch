<script setup lang="ts">
import type { MonitorTypeInfo } from '../../../api/types'
import type { MonitorForm } from './model'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import IntField from './IntField.vue'
import { hasInterval, isHeartbeat } from './model'
import ParentPicker from './ParentPicker.vue'

const props = defineProps<{
  info: MonitorTypeInfo
  /** Top-level validation messages. */
  errors: Record<string, string>
  /** The monitor being edited; null on create. */
  monitorId: number | null
}>()

const form = defineModel<MonitorForm>('form', { required: true })
const { t } = useI18n()

function patch(change: Partial<MonitorForm>): void {
  form.value = {
    ...form.value,
    ...change,
  }
}

const heartbeat = computed(() => isHeartbeat(props.info))

const intervalError = computed(() => form.value.interval_seconds < props.info.min_interval_seconds
  ? t('monitorEditor.common.intervalMin', { min: props.info.min_interval_seconds })
  : undefined)
</script>

<template>
  <v-text-field
    :model-value="form.name"
    name="monitor_name"
    :label="t('monitorEditor.common.name')"
    :error-messages="errors.name"
    maxlength="120"
    autocomplete="off"
    @update:model-value="patch({ name: $event })"
  />
  <v-textarea
    :model-value="form.description ?? ''"
    name="monitor_description"
    :label="t('monitorEditor.common.description')"
    rows="2"
    auto-grow
    :error-messages="errors.description"
    @update:model-value="patch({ description: $event.trim() === '' ? null : $event })"
  />
  <ParentPicker
    :model-value="form.parent_id"
    :exclude-id="monitorId"
    :error="errors.parent_id"
    @update:model-value="patch({ parent_id: $event })"
  />
  <v-switch
    :model-value="form.active"
    name="monitor_active"
    class="mt-2"
    :label="t('monitorEditor.common.active')"
    :hint="t('monitorEditor.common.activeHint')"
    persistent-hint
    color="primary"
    :error-messages="errors.active"
    @update:model-value="patch({ active: $event === true })"
  />
  <v-switch
    v-if="info.supports_inverted"
    :model-value="form.inverted"
    name="monitor_inverted"
    data-test="inverted"
    :label="t('monitorEditor.common.inverted')"
    :hint="t('monitorEditor.common.invertedHint')"
    persistent-hint
    color="primary"
    :error-messages="errors.inverted"
    @update:model-value="patch({ inverted: $event === true })"
  />

  <template v-if="hasInterval(info)">
    <h3 class="text-subtitle-1 mt-6 mb-2">
      {{ t('monitorEditor.common.timing') }}
    </h3>
    <IntField
      :model-value="form.interval_seconds"
      name="interval_seconds"
      :label="t(heartbeat ? 'monitorEditor.common.heartbeatInterval' : 'monitorEditor.common.interval')"
      :hint="heartbeat ? t('monitorEditor.common.heartbeatIntervalHint') : undefined"
      :persistent-hint="heartbeat"
      :min="info.min_interval_seconds"
      suffix="s"
      :error-messages="errors.interval_seconds ?? intervalError"
      @update:model-value="patch({ interval_seconds: $event ?? 0 })"
    />
  </template>
  <template v-if="info.scheduled">
    <IntField
      :model-value="form.timeout_seconds"
      name="timeout_seconds"
      :label="t('monitorEditor.common.timeout')"
      :hint="t('monitorEditor.common.timeoutHint')"
      persistent-hint
      min="1"
      max="120"
      suffix="s"
      :error-messages="errors.timeout_seconds"
      @update:model-value="patch({ timeout_seconds: $event ?? 0 })"
    />
    <IntField
      :model-value="form.retries"
      name="retries"
      class="mt-4"
      :label="t('monitorEditor.common.retries')"
      :hint="t('monitorEditor.common.retriesHint')"
      persistent-hint
      min="0"
      max="10"
      :error-messages="errors.retries"
      @update:model-value="patch({ retries: $event ?? 0 })"
    />
    <IntField
      :model-value="form.degraded_threshold_ms"
      name="degraded_threshold_ms"
      class="mt-4"
      :label="t('monitorEditor.common.degradedThreshold')"
      :hint="t('monitorEditor.common.degradedThresholdHint')"
      persistent-hint
      min="1"
      suffix="ms"
      :error-messages="errors.degraded_threshold_ms"
      @update:model-value="patch({ degraded_threshold_ms: $event })"
    />
  </template>
  <IntField
    v-if="hasInterval(info)"
    :model-value="form.retry_interval_seconds"
    name="retry_interval_seconds"
    class="mt-4"
    :label="t('monitorEditor.common.retryInterval')"
    :hint="heartbeat ? t('monitorEditor.common.heartbeatRetryHint') : undefined"
    :persistent-hint="heartbeat"
    min="20"
    suffix="s"
    :error-messages="errors.retry_interval_seconds"
    @update:model-value="patch({ retry_interval_seconds: $event ?? 0 })"
  />
</template>
