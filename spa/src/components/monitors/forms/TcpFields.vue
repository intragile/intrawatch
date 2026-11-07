<script setup lang="ts">
import type { Config, SecretDraft, TypeFieldProps } from './model'
import { useI18n } from 'vue-i18n'
import IntField from './IntField.vue'
import { useConfigFields } from './useConfigFields'

const props = defineProps<TypeFieldProps>()
const config = defineModel<Config>('config', { required: true })
defineModel<SecretDraft>('secrets')
const { t } = useI18n()
const f = useConfigFields(config, () => props.errors)
</script>

<template>
  <v-text-field
    :model-value="f.str('host')"
    name="config_host"
    :label="t('monitorEditor.tcp.host')"
    :error-messages="f.err('host')"
    autocomplete="off"
    @update:model-value="f.set('host', $event)"
  />
  <IntField
    :model-value="f.num('port')"
    name="config_port"
    :label="t('monitorEditor.tcp.port')"
    min="1"
    max="65535"
    :error-messages="f.err('port')"
    @update:model-value="f.set('port', $event)"
  />
</template>
