<script setup lang="ts">
import { mdiCheckCircle, mdiCloseCircle, mdiSend } from '@mdi/js'
import { useI18n } from 'vue-i18n'

export interface TestResult {
  ok: boolean
  /** The provider's own error text when the send failed. */
  error?: string | null
}

defineProps<{
  loading?: boolean
  disabled?: boolean
  /** Why the button is inactive, shown under it. */
  note?: string
  result: TestResult | null
}>()

defineEmits<{ test: [] }>()
const { t } = useI18n()
</script>

<template>
  <div class="d-flex align-center flex-wrap ga-3">
    <v-btn
      data-test="channel-test"
      variant="tonal"
      :prepend-icon="mdiSend"
      :loading="loading"
      :disabled="disabled"
      @click="$emit('test')"
    >
      {{ t('channels.test.send') }}
    </v-btn>
    <v-chip
      v-if="result?.ok"
      color="success"
      :prepend-icon="mdiCheckCircle"
      label
      data-test="test-result"
    >
      {{ t('channels.test.ok') }}
    </v-chip>
    <v-chip
      v-else-if="result"
      color="error"
      :prepend-icon="mdiCloseCircle"
      label
      data-test="test-result"
    >
      {{ t('channels.test.failed') }}
    </v-chip>
  </div>
  <p v-if="note" class="text-caption text-medium-emphasis mt-1 mb-0">
    {{ note }}
  </p>
  <v-alert
    v-if="result && !result.ok"
    type="error"
    variant="tonal"
    class="mt-2"
    role="alert"
    data-test="test-error"
  >
    <span style="white-space: pre-line">{{ result.error ?? t('channels.test.unknown') }}</span>
  </v-alert>
</template>
