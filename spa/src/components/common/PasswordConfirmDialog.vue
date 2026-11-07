<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '../../api'
import { confirmPassword } from '../../auth/fortify'
import { passwordPrompt } from '../../auth/prompts'
import { asApiError } from '../../utils/errors'
import ProblemAlert from './ProblemAlert.vue'

const { t } = useI18n()
const open = passwordPrompt.open
const password = ref('')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const fields = computed(() => fieldErrors(error.value))

watch(open, (isOpen) => {
  if (isOpen) {
    password.value = ''
    error.value = null
  }
})

function finish(ok: boolean): void {
  password.value = ''
  passwordPrompt.settle(ok)
}

async function submit(): Promise<void> {
  if (busy.value || password.value === '')
    return
  busy.value = true
  error.value = null
  try {
    await confirmPassword(password.value)
    finish(true)
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
  <v-dialog :model-value="open" persistent max-width="440">
    <v-card :title="t('auth.confirmPassword.title')">
      <v-form @submit.prevent="submit">
        <v-card-text>
          <p class="mb-4">
            {{ t('auth.confirmPassword.intro') }}
          </p>
          <ProblemAlert :error="error" :known-fields="['password']" />
          <v-text-field
            v-model="password"
            name="confirm_password"
            type="password"
            autocomplete="current-password"
            :label="t('auth.fields.password')"
            :error-messages="fields.password"
            autofocus
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="busy" @click="finish(false)">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn type="submit" color="primary" variant="flat" :loading="busy">
            {{ t('auth.confirmPassword.submit') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </v-dialog>
</template>
