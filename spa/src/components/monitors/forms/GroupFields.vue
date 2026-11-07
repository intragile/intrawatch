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

const MODES = ['all', 'any', 'quorum'] as const

const modesItems = computed(() => MODES.map(v => ({
  value: v,
  title: t(`monitorEditor.group.modes.${v}`),
})))
</script>

<template>
  <v-select
    :model-value="f.str('mode')"
    name="config_mode"
    :items="modesItems"
    :label="t('monitorEditor.group.mode')"
    :hint="t(`monitorEditor.group.modeHints.${f.str('mode') || 'all'}`)"
    persistent-hint
    :error-messages="f.err('mode')"
    @update:model-value="f.set('mode', $event)"
  />
  <IntField
    v-if="f.str('mode') === 'quorum'"
    :model-value="f.num('quorum')"
    name="config_quorum"
    class="mt-4"
    :label="t('monitorEditor.group.quorum')"
    :hint="t('monitorEditor.group.quorumHint')"
    persistent-hint
    min="1"
    :error-messages="f.err('quorum')"
    @update:model-value="f.set('quorum', $event)"
  />
</template>
