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
    :label="t('monitorEditor.ping.host')"
    :error-messages="f.err('host')"
    autocomplete="off"
    @update:model-value="f.set('host', $event)"
  />
  <IntField
    :model-value="f.num('count')"
    name="config_count"
    :label="t('monitorEditor.ping.count')"
    :hint="t('monitorEditor.ping.countHint')"
    persistent-hint
    min="1"
    max="5"
    :error-messages="f.err('count')"
    @update:model-value="f.set('count', $event)"
  />
</template>
