<script setup lang="ts">
import type { TwoFactorAnswer } from '../../auth/fortify'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps<{
  /** Field error from the API (`code` or `recovery_code`). */
  error?: string
}>()

const { t } = useI18n()
const useRecovery = ref(false)
const code = ref('')
const recoveryCode = ref('')

const answer = computed<TwoFactorAnswer | null>(() => {
  if (useRecovery.value) {
    const value = recoveryCode.value.trim()
    return value
      ? { recovery_code: value }
      : null
  }
  const value = code.value.replace(/\s+/g, '')
  return value
    ? { code: value }
    : null
})

function toggle(): void {
  useRecovery.value = !useRecovery.value
  code.value = ''
  recoveryCode.value = ''
}

defineExpose({ answer })
</script>

<template>
  <div>
    <v-text-field
      v-if="!useRecovery"
      v-model="code"
      name="code"
      :label="t('auth.twoFactor.code')"
      :hint="t('auth.twoFactor.codeHint')"
      persistent-hint
      inputmode="numeric"
      autocomplete="one-time-code"
      maxlength="10"
      :error-messages="error"
      autofocus
    />
    <v-text-field
      v-else
      v-model="recoveryCode"
      name="recovery_code"
      :label="t('auth.twoFactor.recoveryCode')"
      :hint="t('auth.twoFactor.recoveryHint')"
      persistent-hint
      autocomplete="off"
      :error-messages="error"
      autofocus
    />
    <v-btn
      data-test="use-recovery"
      variant="text"
      size="small"
      class="mt-2 px-0"
      @click="toggle"
    >
      {{ useRecovery ? t('auth.twoFactor.useCode') : t('auth.twoFactor.useRecovery') }}
    </v-btn>
  </div>
</template>
