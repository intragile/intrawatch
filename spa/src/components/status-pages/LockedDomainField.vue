<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  /** The domain the page has on the server. */
  stored: string
  /** Hides the remove action. */
  disabled: boolean
  error?: string
}>()

/** Either the stored domain or empty once removal is chosen; nothing else can be typed in. */
const domain = defineModel<string>({ required: true })
const { t } = useI18n()
</script>

<template>
  <div class="d-flex align-start ga-2">
    <v-text-field
      :model-value="domain"
      name="status_custom_domain_locked"
      data-test="custom-domain-locked"
      class="flex-grow-1"
      :label="t('statusPages.fields.customDomain')"
      :hint="domain === '' ? t('statusPages.hints.customDomainRemoved') : t('statusPages.hints.customDomainLocked')"
      persistent-hint
      readonly
      autocomplete="off"
      :error-messages="error"
    />
    <template v-if="!disabled">
      <v-btn
        v-if="domain !== ''"
        data-test="custom-domain-remove"
        variant="text"
        color="error"
        class="mt-2"
        @click="domain = ''"
      >
        {{ t('statusPages.removeDomain') }}
      </v-btn>
      <v-btn
        v-else
        data-test="custom-domain-keep"
        variant="text"
        class="mt-2"
        @click="domain = stored"
      >
        {{ t('statusPages.keepDomain') }}
      </v-btn>
    </template>
  </div>
</template>
