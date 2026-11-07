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
  <v-alert type="info" variant="tonal" class="mb-4">
    {{ t('monitorEditor.push.info') }}
  </v-alert>
  <IntField
    :model-value="f.num('grace_seconds')"
    name="config_grace_seconds"
    :label="t('monitorEditor.push.grace')"
    :hint="t('monitorEditor.push.graceHint')"
    persistent-hint
    min="0"
    max="3600"
    suffix="s"
    :error-messages="f.err('grace_seconds')"
    @update:model-value="f.set('grace_seconds', $event)"
  />
</template>
