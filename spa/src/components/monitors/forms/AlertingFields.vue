<script setup lang="ts">
import type { MonitorForm } from './model'
import { useI18n } from 'vue-i18n'
import IntField from './IntField.vue'

defineProps<{
  /** Validation messages keyed relative to `alerting.`. */
  errors: Record<string, string>
}>()

const alerting = defineModel<MonitorForm['alerting']>({ required: true })
const { t } = useI18n()

function patch(change: Partial<MonitorForm['alerting']>): void {
  alerting.value = {
    ...alerting.value,
    ...change,
  }
}
</script>

<template>
  <IntField
    :model-value="alerting.resend_interval_minutes"
    name="alerting_resend_interval_minutes"
    :label="t('monitorEditor.alerting.resend')"
    :hint="t('monitorEditor.alerting.resendHint')"
    persistent-hint
    min="0"
    max="10080"
    :error-messages="errors.resend_interval_minutes"
    @update:model-value="patch({ resend_interval_minutes: $event ?? 0 })"
  />
  <v-switch
    :model-value="alerting.notify_changes"
    name="alerting_notify_changes"
    class="mt-2"
    :label="t('monitorEditor.alerting.notifyChanges')"
    :error-messages="errors.notify_changes"
    color="primary"
    @update:model-value="patch({ notify_changes: $event === true })"
  />
  <v-switch
    :model-value="alerting.notify_degraded"
    name="alerting_notify_degraded"
    :label="t('monitorEditor.alerting.notifyDegraded')"
    :error-messages="errors.notify_degraded"
    color="primary"
    @update:model-value="patch({ notify_degraded: $event === true })"
  />
  <IntField
    v-if="alerting.notify_degraded"
    :model-value="alerting.degraded_alert_after_seconds"
    name="alerting_degraded_alert_after_seconds"
    :label="t('monitorEditor.alerting.degradedAfter')"
    :hint="t('monitorEditor.alerting.degradedAfterHint')"
    persistent-hint
    min="0"
    max="86400"
    suffix="s"
    :error-messages="errors.degraded_alert_after_seconds"
    @update:model-value="patch({ degraded_alert_after_seconds: $event ?? 0 })"
  />
</template>
