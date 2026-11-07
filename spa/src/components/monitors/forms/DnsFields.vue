<script setup lang="ts">
import type { Config, SecretDraft, TypeFieldProps } from './model'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ListField from './ListField.vue'
import { useConfigFields } from './useConfigFields'

const props = defineProps<TypeFieldProps>()
const config = defineModel<Config>('config', { required: true })
defineModel<SecretDraft>('secrets')
const { t } = useI18n()
const f = useConfigFields(config, () => props.errors)

const RECORD_TYPES = [
  'A',
  'AAAA',
  'CNAME',
  'MX',
  'NS',
  'TXT',
  'SOA',
  'SRV',
  'CAA',
  'PTR',
]
const MATCH_MODES = ['any', 'all', 'exact'] as const

const matchmodesItems = computed(() => MATCH_MODES.map(v => ({
  value: v,
  title: t(`monitorEditor.dns.matchModes.${v}`),
})))

function expected(): string[] {
  const v = config.value.expected
  return Array.isArray(v)
    ? v.map(String)
    : []
}

function setExpected(values: Array<string | number>): void {
  f.set('expected', values.length > 0
    ? values
    : null)
}
</script>

<template>
  <v-text-field
    :model-value="f.str('name')"
    name="config_name"
    :label="t('monitorEditor.dns.name')"
    :error-messages="f.err('name')"
    autocomplete="off"
    @update:model-value="f.set('name', $event)"
  />
  <v-select
    :model-value="f.str('record_type')"
    name="config_record_type"
    :items="RECORD_TYPES"
    :label="t('monitorEditor.dns.recordType')"
    :error-messages="f.err('record_type')"
    @update:model-value="f.set('record_type', $event)"
  />
  <v-text-field
    :model-value="f.str('resolver')"
    name="config_resolver"
    :label="t('monitorEditor.dns.resolver')"
    :hint="t('monitorEditor.dns.resolverHint')"
    persistent-hint
    :error-messages="f.err('resolver')"
    autocomplete="off"
    @update:model-value="f.set('resolver', f.nullable($event))"
  />
  <ListField
    :model-value="expected()"
    name="config_expected"
    class="mt-4"
    :label="t('monitorEditor.dns.expected')"
    :hint="t('monitorEditor.dns.expectedHint')"
    persistent-hint
    :error-messages="f.err('expected')"
    @update:model-value="setExpected($event ?? [])"
  />
  <v-select
    :model-value="f.str('match')"
    name="config_match"
    class="mt-4"
    :items="matchmodesItems"
    :label="t('monitorEditor.dns.match')"
    :error-messages="f.err('match')"
    @update:model-value="f.set('match', $event)"
  />
  <v-switch
    :model-value="f.bool('watch_changes')"
    name="config_watch_changes"
    :label="t('monitorEditor.dns.watchChanges')"
    :error-messages="f.err('watch_changes')"
    color="primary"
    @update:model-value="f.set('watch_changes', $event === true)"
  />
</template>
