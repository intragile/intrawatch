<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, fieldErrors, unwrap } from '../../api'
import { useNotify } from '../../composables/useNotify'
import { PASSWORD_MIN, usePasswordRules } from '../../composables/usePasswordRules'
import { asApiError } from '../../utils/errors'
import ProblemAlert from '../common/ProblemAlert.vue'

const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const { notify } = useNotify()

const current = ref('')
const password = ref('')
const confirmation = ref('')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const form = ref<{ validate: () => Promise<{ valid: boolean }>, resetValidation: () => void } | null>(null)
const fields = computed(() => fieldErrors(error.value))
const rules = usePasswordRules(password)

async function submit(): Promise<void> {
  if (busy.value || !(await form.value?.validate())?.valid)
    return
  busy.value = true
  error.value = null
  try {
    await unwrap<void>(api.PUT('/me/password', {
      body: {
        current_password: current.value,
        password: password.value,
        password_confirmation: confirmation.value,
      },
    }))
    current.value = ''
    password.value = ''
    confirmation.value = ''
    form.value?.resetValidation()
    notify(t('account.password.done'))
    emit('changed')
  }
  catch (e) {
    error.value = asApiError(e)
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <v-card max-width="720">
    <v-card-title tag="h2" class="text-h6">
      {{ t('account.password.title') }}
    </v-card-title>
    <v-form ref="form" @submit.prevent="submit">
      <v-card-text>
        <p class="mb-4">
          {{ t('account.password.intro') }}
        </p>
        <ProblemAlert :error="error" :known-fields="['current_password', 'password']" />
        <v-text-field
          v-model="current"
          name="current_password"
          type="password"
          autocomplete="current-password"
          :label="t('account.password.current')"
          :rules="[rules.required]"
          :error-messages="fields.current_password"
          max-width="400"
        />
        <v-text-field
          v-model="password"
          name="password"
          type="password"
          autocomplete="new-password"
          :label="t('auth.fields.newPassword')"
          :hint="t('validation.passwordMin', { min: PASSWORD_MIN })"
          :rules="[rules.required, rules.min]"
          :error-messages="fields.password"
          max-width="400"
        />
        <v-text-field
          v-model="confirmation"
          name="password_confirmation"
          type="password"
          autocomplete="new-password"
          :label="t('auth.fields.passwordConfirmation')"
          :rules="[rules.required, rules.matches]"
          max-width="400"
        />
        <v-btn data-test="password-submit" type="submit" color="primary" variant="flat" :loading="busy">
          {{ t('account.password.submit') }}
        </v-btn>
      </v-card-text>
    </v-form>
  </v-card>
</template>
