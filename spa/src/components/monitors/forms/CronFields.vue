<script setup lang="ts">
import type { Config, SecretDraft, TypeFieldProps } from './model'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import IntField from './IntField.vue'
import { useConfigFields } from './useConfigFields'

const props = defineProps<TypeFieldProps>()
const config = defineModel<Config>('config', { required: true })
defineModel<SecretDraft>('secrets')
const { t } = useI18n()
const f = useConfigFields(config, () => props.errors)

/** IANA zones known to the browser. */
const zones = computed<string[]>(() => typeof Intl.supportedValuesOf === 'function'
  ? Intl.supportedValuesOf('timeZone')
  : [])
</script>

<template>
  <v-alert type="info" variant="tonal" class="mb-4">
    {{ t('monitorEditor.cron.info') }}
  </v-alert>
  <v-text-field
    :model-value="f.str('schedule')"
    name="config_schedule"
    :label="t('monitorEditor.cron.schedule')"
    :hint="t('monitorEditor.cron.scheduleHint')"
    persistent-hint
    :error-messages="f.err('schedule')"
    autocomplete="off"
    @update:model-value="f.set('schedule', $event)"
  />
  <v-autocomplete
    :model-value="f.str('timezone') || null"
    name="config_timezone"
    class="mt-4"
    :items="zones"
    clearable
    :label="t('monitorEditor.cron.timezone')"
    :hint="t('monitorEditor.cron.timezoneHint')"
    persistent-hint
    :error-messages="f.err('timezone')"
    @update:model-value="f.set('timezone', $event || null)"
  />
  <IntField
    :model-value="f.num('grace_seconds')"
    name="config_grace_seconds"
    class="mt-4"
    :label="t('monitorEditor.cron.grace')"
    :hint="t('monitorEditor.cron.graceHint')"
    persistent-hint
    min="0"
    max="86400"
    suffix="s"
    :error-messages="f.err('grace_seconds')"
    @update:model-value="f.set('grace_seconds', $event)"
  />
  <IntField
    :model-value="f.num('max_runtime_seconds')"
    name="config_max_runtime_seconds"
    class="mt-4"
    :label="t('monitorEditor.cron.maxRuntime')"
    :hint="t('monitorEditor.cron.maxRuntimeHint')"
    persistent-hint
    min="1"
    max="86400"
    suffix="s"
    :error-messages="f.err('max_runtime_seconds')"
    @update:model-value="f.set('max_runtime_seconds', $event)"
  />
</template>
