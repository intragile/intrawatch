<script setup lang="ts">
import type { Config, SecretDraft, TypeFieldProps } from './model'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import IntField from './IntField.vue'
import ListField from './ListField.vue'
import { useConfigFields } from './useConfigFields'

const props = defineProps<TypeFieldProps>()
const config = defineModel<Config>('config', { required: true })
defineModel<SecretDraft>('secrets')
const { t } = useI18n()
const f = useConfigFields(config, () => props.errors)

const STARTTLS = ['none', 'smtp', 'imap', 'pop3'] as const

const starttlsItems = computed(() => STARTTLS.map(v => ({
  value: v,
  title: t(`monitorEditor.tls.starttlsModes.${v}`),
})))

function days(): number[] {
  const v = config.value.expiry_days
  return Array.isArray(v)
    ? v.filter((n): n is number => typeof n === 'number')
    : []
}
</script>

<template>
  <v-text-field
    :model-value="f.str('host')"
    name="config_host"
    :label="t('monitorEditor.tls.host')"
    :error-messages="f.err('host')"
    autocomplete="off"
    @update:model-value="f.set('host', $event)"
  />
  <IntField
    :model-value="f.num('port')"
    name="config_port"
    :label="t('monitorEditor.tls.port')"
    min="1"
    max="65535"
    :error-messages="f.err('port')"
    @update:model-value="f.set('port', $event)"
  />
  <v-select
    :model-value="f.str('starttls')"
    name="config_starttls"
    :items="starttlsItems"
    :label="t('monitorEditor.tls.starttls')"
    :error-messages="f.err('starttls')"
    @update:model-value="f.set('starttls', $event)"
  />
  <v-text-field
    :model-value="f.str('servername')"
    name="config_servername"
    :label="t('monitorEditor.tls.servername')"
    :hint="t('monitorEditor.tls.servernameHint')"
    persistent-hint
    :error-messages="f.err('servername')"
    autocomplete="off"
    @update:model-value="f.set('servername', f.nullable($event))"
  />
  <ListField
    :model-value="days()"
    numeric
    name="config_expiry_days"
    class="mt-4"
    :label="t('monitorEditor.tls.expiryDays')"
    :hint="t('monitorEditor.tls.expiryDaysHint')"
    persistent-hint
    :error-messages="f.err('expiry_days')"
    @update:model-value="f.set('expiry_days', $event)"
  />
  <IntField
    :model-value="f.num('degraded_below_days')"
    name="config_degraded_below_days"
    class="mt-4"
    :label="t('monitorEditor.tls.degradedBelowDays')"
    min="0"
    max="3650"
    :error-messages="f.err('degraded_below_days')"
    @update:model-value="f.set('degraded_below_days', $event)"
  />
  <v-switch
    :model-value="f.bool('check_protocols')"
    name="config_check_protocols"
    :label="t('monitorEditor.tls.checkProtocols')"
    :error-messages="f.err('check_protocols')"
    color="primary"
    @update:model-value="f.set('check_protocols', $event === true)"
  />
  <v-switch
    :model-value="f.bool('check_ciphers')"
    name="config_check_ciphers"
    :label="t('monitorEditor.tls.checkCiphers')"
    :error-messages="f.err('check_ciphers')"
    color="primary"
    @update:model-value="f.set('check_ciphers', $event === true)"
  />
  <v-switch
    :model-value="f.bool('watch_certificate')"
    name="config_watch_certificate"
    :label="t('monitorEditor.tls.watchCertificate')"
    :error-messages="f.err('watch_certificate')"
    color="primary"
    @update:model-value="f.set('watch_certificate', $event === true)"
  />
</template>
