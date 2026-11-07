<script setup lang="ts">
import type { Config, SecretDraft, TypeFieldProps } from './model'
import { useI18n } from 'vue-i18n'
import IntField from './IntField.vue'
import ListField from './ListField.vue'
import { useConfigFields } from './useConfigFields'

const props = defineProps<TypeFieldProps>()
const config = defineModel<Config>('config', { required: true })
defineModel<SecretDraft>('secrets')
const { t } = useI18n()
const f = useConfigFields(config, () => props.errors)

function days(): number[] {
  const v = config.value.expiry_days
  return Array.isArray(v)
    ? v.filter((n): n is number => typeof n === 'number')
    : []
}
</script>

<template>
  <v-text-field
    :model-value="f.str('domain')"
    name="config_domain"
    :label="t('monitorEditor.domain.domain')"
    :hint="t('monitorEditor.domain.domainHint')"
    persistent-hint
    :error-messages="f.err('domain')"
    autocomplete="off"
    @update:model-value="f.set('domain', $event)"
  />
  <ListField
    :model-value="days()"
    numeric
    name="config_expiry_days"
    class="mt-4"
    :label="t('monitorEditor.domain.expiryDays')"
    :hint="t('monitorEditor.domain.expiryDaysHint')"
    persistent-hint
    :error-messages="f.err('expiry_days')"
    @update:model-value="f.set('expiry_days', $event)"
  />
  <IntField
    :model-value="f.num('degraded_below_days')"
    name="config_degraded_below_days"
    class="mt-4"
    :label="t('monitorEditor.domain.degradedBelowDays')"
    min="0"
    max="3650"
    :error-messages="f.err('degraded_below_days')"
    @update:model-value="f.set('degraded_below_days', $event)"
  />
</template>
