<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  disabled: boolean
  error?: string
}>()

const domain = defineModel<string>({ required: true })
const { t } = useI18n()
</script>

<template>
  <v-text-field
    v-model="domain"
    name="status_custom_domain"
    data-test="custom-domain"
    :label="t('statusPages.fields.customDomain')"
    :hint="t('statusPages.hints.customDomain')"
    placeholder="status.example.com"
    persistent-hint
    maxlength="253"
    autocomplete="off"
    :disabled="disabled"
    :error-messages="error"
  />
</template>
