<script setup lang="ts">
import type { ChannelType } from '../../api/types'
import type { Config, SecretDraft } from '../monitors/forms/model'
import type { FieldSpec } from './fields'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import SecretField from '../common/SecretField.vue'
import IntField from '../monitors/forms/IntField.vue'
import ListField from '../monitors/forms/ListField.vue'
import { invalidHeaderLines, parseHeaderLines } from '../monitors/forms/model'
import { useConfigFields } from '../monitors/forms/useConfigFields'
import { CHANNEL_FIELDS, renderedSecrets, SMTP_FIELDS } from './fields'

const props = defineProps<{
  type: ChannelType
  /** Config validation messages, keyed relative to `config.`. */
  errors: Record<string, string>
  /** Keys of the secrets the channel already stores. */
  secretKeys: string[]
  /** Secret validation messages, keyed relative to `secrets.`. */
  secretErrors: Record<string, string>
  /** Stored secrets that must be entered again (or cleared) because the destination changed. */
  requiredSecrets?: string[]
}>()

const config = defineModel<Config>('config', { required: true })
const secrets = defineModel<SecretDraft>('secrets', { required: true })

const { t } = useI18n()
const cf = useConfigFields(config, () => props.errors)

/** Specs with a control on screen: secrets that depend on a config value appear only with it. */
const shown = computed(() => {
  const secretNow = new Set(renderedSecrets(props.type, config.value).map(f => f.key))
  return CHANNEL_FIELDS[props.type].filter(f => f.kind !== 'secret' || secretNow.has(f.key))
})

const mapText = ref<Record<string, string>>({})

function mapSource(key: string): string {
  const cached = mapText.value[key]
  if (cached !== undefined)
    return cached
  return Object.entries(cf.obj(key)).map(([name, value]) => `${name}: ${String(value)}`).join('\n')
}

function setMap(key: string, source: string | null): void {
  const text = source ?? ''
  mapText.value = { ...mapText.value, [key]: text }
  cf.set(key, parseHeaderLines(text))
}

function mapError(key: string): string | undefined {
  const bad = invalidHeaderLines(mapSource(key))
  return cf.err(key) ?? (bad.length > 0
    ? t('channels.mapInvalid', {
        lines: bad.join(', '),
      })
    : undefined)
}

function text(spec: FieldSpec, value: string | null): void {
  cf.set(spec.key, spec.required
    ? (value ?? '').trim()
    : cf.nullable(value))
}

function label(spec: FieldSpec): string {
  const required = spec.required === true || (spec.kind === 'secret' && (props.requiredSecrets ?? []).includes(spec.key))
  return `${t(`channels.fields.${spec.key}`)}${required ? ' *' : ''}`
}

function hint(spec: FieldSpec): string | undefined {
  if (spec.hint !== undefined)
    return t(`channels.hints.${spec.hint}`)
  if (spec.min !== undefined && spec.max !== undefined)
    return t('channels.range', { min: spec.min, max: spec.max })
  return spec.min === undefined
    ? undefined
    : t('channels.atLeast', { min: spec.min })
}

const smtp = computed(() => config.value.smtp !== null && config.value.smtp !== undefined
  ? cf.obj('smtp')
  : null)

function toggleSmtp(on: boolean | null): void {
  cf.set('smtp', on
    ? {
        host: '',
        port: 587,
        encryption: 'starttls',
        username: null,
        from_address: '',
        from_name: null,
      }
    : null)
}

function setSmtp(key: string, value: unknown): void {
  cf.setIn('smtp', key, value)
}

function smtpText(spec: FieldSpec, value: string | null): void {
  setSmtp(spec.key, spec.required
    ? (value ?? '').trim()
    : cf.nullable(value))
}

function smtpValue(key: string): string {
  const v = cf.obj('smtp')[key]
  return typeof v === 'string'
    ? v
    : ''
}

function setSecret(key: string, value: string | null | undefined): void {
  secrets.value = { ...secrets.value, [key]: value }
}

function listValue(key: string): string[] {
  const v = config.value[key]
  return Array.isArray(v)
    ? v.map(String)
    : []
}
</script>

<template>
  <template v-for="spec in shown" :key="spec.key">
    <v-text-field
      v-if="spec.kind === 'text' || spec.kind === 'url'"
      :model-value="cf.str(spec.key)"
      :name="`config_${spec.key}`"
      :label="label(spec)"
      :type="spec.kind === 'url' ? 'url' : 'text'"
      :hint="hint(spec)"
      :persistent-hint="spec.hint !== undefined"
      :error-messages="cf.err(spec.key)"
      autocomplete="off"
      @update:model-value="text(spec, $event)"
    />
    <IntField
      v-else-if="spec.kind === 'number'"
      :model-value="cf.num(spec.key)"
      :name="`config_${spec.key}`"
      :label="label(spec)"
      :min="spec.min"
      :max="spec.max"
      :hint="hint(spec)"
      persistent-hint
      :error-messages="cf.err(spec.key)"
      @update:model-value="cf.set(spec.key, $event)"
    />
    <v-select
      v-else-if="spec.kind === 'select'"
      :model-value="cf.str(spec.key) || null"
      :name="`config_${spec.key}`"
      :data-test="`config-${spec.key}`"
      :items="spec.options"
      :label="label(spec)"
      :clearable="!spec.required"
      :error-messages="cf.err(spec.key)"
      @update:model-value="cf.set(spec.key, $event ?? null)"
    />
    <v-switch
      v-else-if="spec.kind === 'switch'"
      :model-value="cf.bool(spec.key)"
      :name="`config_${spec.key}`"
      :label="label(spec)"
      color="primary"
      inset
      hide-details="auto"
      :error-messages="cf.err(spec.key)"
      @update:model-value="cf.set(spec.key, $event === true)"
    />
    <ListField
      v-else-if="spec.kind === 'list'"
      :model-value="listValue(spec.key)"
      :name="`config_${spec.key}`"
      :label="label(spec)"
      :hint="t('channels.listHint')"
      persistent-hint
      :error-messages="cf.err(spec.key)"
      @update:model-value="cf.set(spec.key, $event ?? [])"
    />
    <v-textarea
      v-else-if="spec.kind === 'map'"
      :model-value="mapSource(spec.key)"
      :name="`config_${spec.key}`"
      :label="label(spec)"
      :hint="t('channels.mapHint')"
      persistent-hint
      rows="3"
      spellcheck="false"
      :error-messages="mapError(spec.key)"
      @update:model-value="setMap(spec.key, $event)"
    />
    <div v-else-if="spec.kind === 'object'" class="mb-4">
      <v-switch
        :model-value="smtp !== null"
        data-test="smtp-toggle"
        :label="t('channels.smtp.use')"
        :hint="t('channels.smtp.hint')"
        persistent-hint
        color="primary"
        inset
        @update:model-value="toggleSmtp"
      />
      <template v-if="smtp !== null">
        <template v-for="sub in SMTP_FIELDS" :key="sub.key">
          <IntField
            v-if="sub.kind === 'number'"
            :model-value="typeof smtp[sub.key] === 'number' ? (smtp[sub.key] as number) : null"
            :name="`config_smtp_${sub.key}`"
            :label="t(`channels.smtp.${sub.key}`)"
            :error-messages="cf.err(`smtp.${sub.key}`)"
            @update:model-value="setSmtp(sub.key, $event)"
          />
          <v-select
            v-else-if="sub.kind === 'select'"
            :model-value="smtpValue(sub.key) || null"
            :name="`config_smtp_${sub.key}`"
            :items="sub.options"
            :label="t(`channels.smtp.${sub.key}`)"
            clearable
            :error-messages="cf.err(`smtp.${sub.key}`)"
            @update:model-value="setSmtp(sub.key, $event ?? null)"
          />
          <v-text-field
            v-else
            :model-value="smtpValue(sub.key)"
            :name="`config_smtp_${sub.key}`"
            :label="`${t(`channels.smtp.${sub.key}`)}${sub.required ? ' *' : ''}`"
            :error-messages="cf.err(`smtp.${sub.key}`)"
            autocomplete="off"
            @update:model-value="smtpText(sub, $event)"
          />
        </template>
      </template>
    </div>
    <SecretField
      v-else
      :model-value="secrets[spec.key]"
      :name="spec.key"
      :label="label(spec)"
      :multiline="spec.multiline"
      :hint="spec.key === 'secret_headers' ? t('channels.mapHint') : hint(spec)"
      :clearable="!spec.required"
      :is-set="secretKeys.includes(spec.key)"
      :error="secretErrors[spec.key]"
      @update:model-value="setSecret(spec.key, $event)"
    />
  </template>
</template>
