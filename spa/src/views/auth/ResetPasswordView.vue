<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { fieldErrors } from '../../api'
import { resetPassword } from '../../auth/fortify'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useNotify } from '../../composables/useNotify'
import { PASSWORD_MIN, usePasswordRules } from '../../composables/usePasswordRules'
import { asApiError } from '../../utils/errors'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { notify } = useNotify()

const token = computed(() => String(route.params.token ?? ''))
const email = ref(typeof route.query.email === 'string'
  ? route.query.email
  : '')
const password = ref('')
const confirmation = ref('')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const form = ref<{ validate: () => Promise<{ valid: boolean }> } | null>(null)
const fields = computed(() => fieldErrors(error.value))
const rules = usePasswordRules(password)

async function submit(): Promise<void> {
  if (busy.value || !(await form.value?.validate())?.valid)
    return
  busy.value = true
  error.value = null
  try {
    await resetPassword({
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: confirmation.value,
    })
    notify(t('auth.reset.done'))
    await router.replace({ name: 'login' })
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
  <v-form ref="form" @submit.prevent="submit">
    <v-card-title as="h1" class="text-h6">
      {{ t('auth.reset.title') }}
    </v-card-title>
    <v-card-text>
      <ProblemAlert :error="error" :known-fields="['email', 'password']" />
      <v-text-field
        v-model="email"
        name="email"
        type="email"
        autocomplete="username"
        :label="t('auth.fields.email')"
        :rules="[rules.required, rules.email]"
        :error-messages="fields.email"
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
        autofocus
      />
      <v-text-field
        v-model="confirmation"
        name="password_confirmation"
        type="password"
        autocomplete="new-password"
        :label="t('auth.fields.passwordConfirmation')"
        :rules="[rules.required, rules.matches]"
      />
    </v-card-text>
    <v-card-actions class="px-4 pb-4">
      <v-btn variant="text" :to="{ name: 'login' }">
        {{ t('auth.forgot.back') }}
      </v-btn>
      <v-spacer />
      <v-btn type="submit" color="primary" variant="flat" :loading="busy">
        {{ t('auth.reset.submit') }}
      </v-btn>
    </v-card-actions>
  </v-form>
</template>
