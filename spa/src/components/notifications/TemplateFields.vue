<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  /** Validation messages for `title` and `body`. */
  errors: Record<string, string>
}>()

const model = defineModel<{ title: string, body: string }>({ required: true })
const { t } = useI18n()

/** Variables the server's template renderer accepts. */
const VARIABLES = '{{ kind }}, {{ status }}, {{ message }}, {{ at }}, {{ duration }}, {{ monitor.name }}, {{ monitor.url }}, {{ workspace.name }}'

function set(key: 'title' | 'body', value: string | null): void {
  model.value = { ...model.value, [key]: value ?? '' }
}
</script>

<template>
  <p class="text-body-2 text-medium-emphasis mb-2">
    {{ t('channels.templates.intro') }}
  </p>
  <v-text-field
    :model-value="model.title"
    name="templates_title"
    :label="t('channels.templates.title')"
    maxlength="200"
    :error-messages="errors.title"
    autocomplete="off"
    @update:model-value="set('title', $event)"
  />
  <v-textarea
    :model-value="model.body"
    name="templates_body"
    :label="t('channels.templates.body')"
    :hint="t('channels.templates.variables', { list: VARIABLES })"
    persistent-hint
    rows="3"
    maxlength="4000"
    :error-messages="errors.body"
    @update:model-value="set('body', $event)"
  />
</template>
