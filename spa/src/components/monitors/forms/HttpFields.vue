<script setup lang="ts">
import type { Config, SecretDraft, TypeFieldProps } from './model'
import { mdiPlus, mdiTrashCanOutline } from '@mdi/js'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import SecretField from '../../common/SecretField.vue'
import IntField from './IntField.vue'
import ListField from './ListField.vue'
import { errorFor } from './model'
import { useConfigFields } from './useConfigFields'

interface HeaderRow { name: string, value: string }
interface HeaderCheck { name: string, operator: string, value: string | null }

const props = defineProps<TypeFieldProps>()
const config = defineModel<Config>('config', { required: true })
const secrets = defineModel<SecretDraft>('secrets', {
  default: () => ({}),
})
const { t } = useI18n()
const cf = useConfigFields(config, () => props.errors)

// Bound from script: a literal `//` inside a template attribute value upsets the type checker.
const URL_PLACEHOLDER = 'https://'
const PROXY_PLACEHOLDER = 'socks5://host:1080'

const METHODS = [
  'GET',
  'POST',
  'PUT',
  'PATCH',
  'DELETE',
  'HEAD',
  'OPTIONS',
]
const AUTH_TYPES = ['none', 'basic', 'bearer'] as const
const KEYWORD_MODES = ['contains', 'not_contains'] as const
const JSON_OPERATORS = [
  '==',
  '!=',
  '>',
  '>=',
  '<',
  '<=',
  'contains',
  'exists',
]
const HEADER_OPERATORS = [
  'equals',
  'contains',
  'exists',
  'not_exists',
] as const

const authtypesItems = computed(() => AUTH_TYPES.map(v => ({
  value: v,
  title: t(`monitorEditor.http.authTypes.${v}`),
})))
const keywordmodesItems = computed(() => KEYWORD_MODES.map(v => ({
  value: v,
  title: t(`monitorEditor.http.keywordModes.${v}`),
})))
const headeroperatorsItems = computed(() => HEADER_OPERATORS.map(v => ({
  value: v,
  title: t(`monitorEditor.http.headerOperators.${v}`),
})))

const isSet = (key: string): boolean => props.secretKeys.includes(key)
const secretError = (key: string): string | undefined => errorFor(props.secretErrors, key)

function setSecret(key: string, value: string | null | undefined): void {
  const next = { ...secrets.value }
  if (value === undefined)
    delete next[key]
  else
    next[key] = value
  secrets.value = next
}

// Headers are edited as rows (a name can be mid-edit or duplicated) and stored as a name-to-value map.
const headerRows = ref<HeaderRow[]>(Object.entries(cf.obj('headers')).map(([name, value]) => ({
  name,
  value: String(value),
})))

function commitHeaders(): void {
  const map: Record<string, string> = {}
  for (const row of headerRows.value) {
    if (row.name.trim() !== '')
      map[row.name.trim()] = row.value
  }
  cf.set('headers', map)
}

function setHeader(index: number, patch: Partial<HeaderRow>): void {
  headerRows.value = headerRows.value.map((row, i) => i === index
    ? {
        ...row,
        ...patch,
      }
    : row)
  commitHeaders()
}

function addHeader(): void {
  headerRows.value = [
    ...headerRows.value,
    { name: '', value: '' },
  ]
}

function removeHeader(index: number): void {
  headerRows.value = headerRows.value.filter((_, i) => i !== index)
  commitHeaders()
}

function headerError(row: HeaderRow): string | undefined {
  return row.name === ''
    ? undefined
    : props.errors[`headers.${row.name.trim()}`]
}

function checks(): HeaderCheck[] {
  const v = config.value.header_checks
  return Array.isArray(v)
    ? v as HeaderCheck[]
    : []
}

function setCheck(index: number, patch: Partial<HeaderCheck>): void {
  cf.set('header_checks', checks().map((c, i) => i === index
    ? {
        ...c,
        ...patch,
      }
    : c))
}

function addCheck(): void {
  cf.set('header_checks', [
    ...checks(),
    { name: '', operator: 'equals', value: null },
  ])
}

function removeCheck(index: number): void {
  cf.set('header_checks', checks().filter((_, i) => i !== index))
}

const keyword = (): Record<string, unknown> | null => config.value.keyword as Record<string, unknown> | null ?? null
const jsonQuery = (): Record<string, unknown> | null => config.value.json_query as Record<string, unknown> | null ?? null
const proxy = (): Record<string, unknown> | null => config.value.proxy as Record<string, unknown> | null ?? null

function member(block: Record<string, unknown> | null, key: string): string {
  const v = block?.[key]
  return typeof v === 'string'
    ? v
    : ''
}

/** The stored expected value as text; the API accepts any scalar here. */
function expectedText(): string {
  const v = jsonQuery()?.expected
  return v === null || v === undefined
    ? ''
    : String(v)
}

/** Text back to a value, keeping the stored type while the text still reads as that value. */
function parseExpected(text: string | null): unknown {
  const trimmed = (text ?? '').trim()
  if (trimmed === '')
    return null
  const original = jsonQuery()?.expected
  if (typeof original === 'number' && Number.isFinite(Number(trimmed)))
    return Number(trimmed)
  if (typeof original === 'boolean' && (trimmed === 'true' || trimmed === 'false'))
    return trimmed === 'true'
  return trimmed
}

function setBlock(key: string, current: Record<string, unknown> | null, patch: Record<string, unknown>): void {
  cf.set(key, {
    ...current,
    ...patch,
  })
}

function days(): number[] {
  const v = config.value.tls_expiry_days
  return Array.isArray(v)
    ? v.filter((n): n is number => typeof n === 'number')
    : []
}

function statuses(): string[] {
  const v = config.value.accepted_status
  return Array.isArray(v)
    ? v.map(String)
    : []
}
</script>

<template>
  <v-text-field
    :model-value="cf.str('url')"
    name="config_url"
    type="url"
    :label="t('monitorEditor.http.url')"
    :placeholder="URL_PLACEHOLDER"
    :error-messages="cf.err('url')"
    autocomplete="off"
    @update:model-value="cf.set('url', $event)"
  />
  <v-select
    :model-value="cf.str('method')"
    name="config_method"
    :items="METHODS"
    :label="t('monitorEditor.http.method')"
    :error-messages="cf.err('method')"
    @update:model-value="cf.set('method', $event)"
  />
  <ListField
    :model-value="statuses()"
    name="config_accepted_status"
    :label="t('monitorEditor.http.acceptedStatus')"
    :hint="t('monitorEditor.http.acceptedStatusHint')"
    persistent-hint
    :error-messages="cf.err('accepted_status')"
    @update:model-value="cf.set('accepted_status', $event)"
  />
  <IntField
    :model-value="cf.num('max_redirects')"
    name="config_max_redirects"
    class="mt-4"
    :label="t('monitorEditor.http.maxRedirects')"
    min="0"
    max="20"
    :error-messages="cf.err('max_redirects')"
    @update:model-value="cf.set('max_redirects', $event)"
  />
  <v-textarea
    :model-value="cf.str('body')"
    name="config_body"
    :label="t('monitorEditor.http.body')"
    rows="3"
    :error-messages="cf.err('body')"
    @update:model-value="cf.set('body', cf.nullable($event))"
  />

  <h3 class="text-subtitle-1 mt-4 mb-2">
    {{ t('monitorEditor.http.headers') }}
  </h3>
  <p v-if="errors.headers" class="text-caption text-error mb-2" role="alert">
    {{ errors.headers }}
  </p>
  <div v-for="(row, i) in headerRows" :key="i" class="d-flex ga-2 align-start">
    <v-text-field
      :model-value="row.name"
      :name="`config_headers_${i}_name`"
      :label="t('monitorEditor.http.headerName')"
      autocomplete="off"
      @update:model-value="setHeader(i, { name: $event })"
    />
    <v-text-field
      :model-value="row.value"
      :name="`config_headers_${i}_value`"
      :label="t('monitorEditor.http.headerValue')"
      :error-messages="headerError(row)"
      autocomplete="off"
      @update:model-value="setHeader(i, { value: $event })"
    />
    <v-btn
      icon
      variant="text"
      :aria-label="t('monitorEditor.http.removeHeader')"
      @click="removeHeader(i)"
    >
      <v-icon :icon="mdiTrashCanOutline" />
    </v-btn>
  </div>
  <v-btn variant="tonal" size="small" :prepend-icon="mdiPlus" data-test="add-header" @click="addHeader">
    {{ t('monitorEditor.http.addHeader') }}
  </v-btn>
  <p class="text-caption text-medium-emphasis mt-2">
    {{ t('monitorEditor.http.headersHint') }}
  </p>
  <SecretField
    :model-value="secrets.secret_headers"
    name="secret_headers"
    class="mt-2"
    multiline
    :label="t('monitorEditor.http.secretHeaders')"
    :hint="t('monitorEditor.http.secretHeadersHint')"
    :is-set="isSet('secret_headers')"
    :error="secretError('secret_headers')"
    @update:model-value="setSecret('secret_headers', $event)"
  />

  <h3 class="text-subtitle-1 mt-4 mb-2">
    {{ t('monitorEditor.http.authentication') }}
  </h3>
  <v-select
    :model-value="String(cf.obj('auth').type ?? 'none')"
    name="config_auth_type"
    data-test="config-auth-type"
    :items="authtypesItems"
    :label="t('monitorEditor.http.authType')"
    :error-messages="cf.err('auth.type') ?? cf.err('auth')"
    @update:model-value="cf.setIn('auth', 'type', $event)"
  />
  <template v-if="cf.obj('auth').type === 'basic'">
    <v-text-field
      :model-value="typeof cf.obj('auth').username === 'string' ? cf.obj('auth').username as string : ''"
      name="config_auth_username"
      :label="t('monitorEditor.http.username')"
      :error-messages="cf.err('auth.username')"
      autocomplete="off"
      @update:model-value="cf.setIn('auth', 'username', cf.nullable($event))"
    />
    <SecretField
      :model-value="secrets.basic_password"
      name="basic_password"
      :label="t('monitorEditor.http.password')"
      :is-set="isSet('basic_password')"
      :error="secretError('basic_password')"
      @update:model-value="setSecret('basic_password', $event)"
    />
  </template>
  <SecretField
    v-if="cf.obj('auth').type === 'bearer'"
    :model-value="secrets.bearer_token"
    name="bearer_token"
    :label="t('monitorEditor.http.bearerToken')"
    :is-set="isSet('bearer_token')"
    :error="secretError('bearer_token')"
    @update:model-value="setSecret('bearer_token', $event)"
  />

  <h3 class="text-subtitle-1 mt-4 mb-2">
    {{ t('monitorEditor.http.assertions') }}
  </h3>
  <v-switch
    :model-value="keyword() !== null"
    name="config_keyword_enabled"
    :error-messages="errors.keyword"
    :label="t('monitorEditor.http.keywordEnable')"
    color="primary"
    @update:model-value="cf.set('keyword', $event === true ? { value: '', mode: 'contains', case_sensitive: true } : null)"
  />
  <template v-if="keyword() !== null">
    <v-text-field
      :model-value="member(keyword(), 'value')"
      name="config_keyword_value"
      :label="t('monitorEditor.http.keywordValue')"
      :error-messages="cf.err('keyword.value')"
      autocomplete="off"
      @update:model-value="setBlock('keyword', keyword(), { value: $event })"
    />
    <v-select
      :model-value="member(keyword(), 'mode') || 'contains'"
      name="config_keyword_mode"
      :items="keywordmodesItems"
      :label="t('monitorEditor.http.keywordMode')"
      :error-messages="cf.err('keyword.mode')"
      @update:model-value="setBlock('keyword', keyword(), { mode: $event })"
    />
    <v-switch
      :model-value="keyword()?.case_sensitive !== false"
      name="config_keyword_case_sensitive"
      :label="t('monitorEditor.http.keywordCase')"
      color="primary"
      @update:model-value="setBlock('keyword', keyword(), { case_sensitive: $event === true })"
    />
  </template>
  <v-switch
    :model-value="jsonQuery() !== null"
    name="config_json_query_enabled"
    :error-messages="errors.json_query"
    :label="t('monitorEditor.http.jsonEnable')"
    color="primary"
    @update:model-value="cf.set('json_query', $event === true ? { path: '', operator: '==', expected: null } : null)"
  />
  <template v-if="jsonQuery() !== null">
    <v-text-field
      :model-value="member(jsonQuery(), 'path')"
      name="config_json_query_path"
      :label="t('monitorEditor.http.jsonPath')"
      placeholder="$.status"
      :error-messages="cf.err('json_query.path')"
      autocomplete="off"
      @update:model-value="setBlock('json_query', jsonQuery(), { path: $event })"
    />
    <v-select
      :model-value="member(jsonQuery(), 'operator') || '=='"
      name="config_json_query_operator"
      :items="JSON_OPERATORS"
      :label="t('monitorEditor.http.jsonOperator')"
      :error-messages="cf.err('json_query.operator')"
      @update:model-value="setBlock('json_query', jsonQuery(), { operator: $event })"
    />
    <v-text-field
      :model-value="expectedText()"
      name="config_json_query_expected"
      :label="t('monitorEditor.http.jsonExpected')"
      :error-messages="cf.err('json_query.expected')"
      autocomplete="off"
      @update:model-value="setBlock('json_query', jsonQuery(), { expected: parseExpected($event) })"
    />
  </template>

  <h4 class="text-subtitle-2 mt-2 mb-2">
    {{ t('monitorEditor.http.headerChecks') }}
  </h4>
  <p v-if="errors.header_checks" class="text-caption text-error mb-2" role="alert">
    {{ errors.header_checks }}
  </p>
  <div v-for="(check, i) in checks()" :key="i" class="d-flex ga-2 align-start flex-wrap">
    <v-text-field
      :model-value="check.name"
      :name="`config_header_checks_${i}_name`"
      :label="t('monitorEditor.http.checkName')"
      :error-messages="cf.err(`header_checks.${i}.name`)"
      autocomplete="off"
      @update:model-value="setCheck(i, { name: $event })"
    />
    <v-select
      :model-value="check.operator"
      :name="`config_header_checks_${i}_operator`"
      :items="headeroperatorsItems"
      :label="t('monitorEditor.http.checkOperator')"
      :error-messages="cf.err(`header_checks.${i}.operator`)"
      @update:model-value="setCheck(i, { operator: $event })"
    />
    <v-text-field
      :model-value="check.value ?? ''"
      :name="`config_header_checks_${i}_value`"
      :label="t('monitorEditor.http.checkValue')"
      :error-messages="cf.err(`header_checks.${i}.value`)"
      autocomplete="off"
      @update:model-value="setCheck(i, { value: cf.nullable($event) })"
    />
    <v-btn icon variant="text" :aria-label="t('monitorEditor.http.removeCheck')" @click="removeCheck(i)">
      <v-icon :icon="mdiTrashCanOutline" />
    </v-btn>
  </div>
  <v-btn variant="tonal" size="small" :prepend-icon="mdiPlus" data-test="add-header-check" @click="addCheck">
    {{ t('monitorEditor.http.addCheck') }}
  </v-btn>

  <h3 class="text-subtitle-1 mt-6 mb-2">
    {{ t('monitorEditor.http.tls') }}
  </h3>
  <v-switch
    :model-value="cf.bool('tls_verify')"
    name="config_tls_verify"
    :label="t('monitorEditor.http.tlsVerify')"
    :error-messages="cf.err('tls_verify')"
    color="primary"
    @update:model-value="cf.set('tls_verify', $event === true)"
  />
  <v-switch
    :model-value="cf.bool('tls_checks')"
    name="config_tls_checks"
    :label="t('monitorEditor.http.tlsChecks')"
    :error-messages="cf.err('tls_checks')"
    color="primary"
    @update:model-value="cf.set('tls_checks', $event === true)"
  />
  <ListField
    :model-value="days()"
    numeric
    name="config_tls_expiry_days"
    :label="t('monitorEditor.http.tlsExpiryDays')"
    :hint="t('monitorEditor.http.tlsExpiryDaysHint')"
    persistent-hint
    :error-messages="cf.err('tls_expiry_days')"
    @update:model-value="cf.set('tls_expiry_days', $event)"
  />
  <v-switch
    :model-value="cf.bool('domain_expiry')"
    name="config_domain_expiry"
    class="mt-2"
    :label="t('monitorEditor.http.domainExpiry')"
    :error-messages="cf.err('domain_expiry')"
    color="primary"
    @update:model-value="cf.set('domain_expiry', $event === true)"
  />
  <v-switch
    :model-value="cf.bool('mtls')"
    name="config_mtls"
    :label="t('monitorEditor.http.mtls')"
    :error-messages="cf.err('mtls')"
    color="primary"
    @update:model-value="cf.set('mtls', $event === true)"
  />
  <template v-if="cf.bool('mtls')">
    <SecretField
      :model-value="secrets.mtls_cert"
      name="mtls_cert"
      multiline
      :clearable="false"
      :label="t('monitorEditor.http.mtlsCert')"
      :hint="t('monitorEditor.http.pemHint')"
      :is-set="isSet('mtls_cert')"
      :error="secretError('mtls_cert')"
      @update:model-value="setSecret('mtls_cert', $event)"
    />
    <SecretField
      :model-value="secrets.mtls_key"
      name="mtls_key"
      multiline
      :clearable="false"
      :label="t('monitorEditor.http.mtlsKey')"
      :hint="t('monitorEditor.http.pemHint')"
      :is-set="isSet('mtls_key')"
      :error="secretError('mtls_key')"
      @update:model-value="setSecret('mtls_key', $event)"
    />
  </template>

  <h3 class="text-subtitle-1 mt-4 mb-2">
    {{ t('monitorEditor.http.proxy') }}
  </h3>
  <v-switch
    :model-value="proxy() !== null"
    name="config_proxy_enabled"
    :error-messages="errors.proxy"
    :label="t('monitorEditor.http.proxyEnable')"
    color="primary"
    @update:model-value="cf.set('proxy', $event === true ? { url: '', username: null } : null)"
  />
  <template v-if="proxy() !== null">
    <v-text-field
      :model-value="member(proxy(), 'url')"
      name="config_proxy_url"
      :label="t('monitorEditor.http.proxyUrl')"
      :placeholder="PROXY_PLACEHOLDER"
      :error-messages="cf.err('proxy.url')"
      autocomplete="off"
      @update:model-value="setBlock('proxy', proxy(), { url: $event })"
    />
    <v-text-field
      :model-value="member(proxy(), 'username')"
      name="config_proxy_username"
      :label="t('monitorEditor.http.proxyUsername')"
      :error-messages="cf.err('proxy.username')"
      autocomplete="off"
      @update:model-value="setBlock('proxy', proxy(), { username: cf.nullable($event) })"
    />
    <SecretField
      :model-value="secrets.proxy_password"
      name="proxy_password"
      :label="t('monitorEditor.http.proxyPassword')"
      :is-set="isSet('proxy_password')"
      :error="secretError('proxy_password')"
      @update:model-value="setSecret('proxy_password', $event)"
    />
  </template>
</template>
