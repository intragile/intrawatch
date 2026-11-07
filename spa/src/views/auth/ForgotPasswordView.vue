<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '../../api'
import { sendPasswordResetLink } from '../../auth/fortify'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { asApiError } from '../../utils/errors'

const { t } = useI18n()
const email = ref('')
const busy = ref(false)
const sent = ref(false)
const error = ref<ApiError | null>(null)
const fields = computed(() => fieldErrors(error.value))

async function submit(): Promise<void> {
  if (busy.value)
    return
  busy.value = true
  error.value = null
  try {
    await sendPasswordResetLink(email.value)
    sent.value = true
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
  <v-form @submit.prevent="submit">
    <v-card-title as="h1" class="text-h6">
      {{ t('auth.forgot.title') }}
    </v-card-title>
    <v-card-text>
      <v-alert v-if="sent" type="success" variant="tonal" role="status" class="mb-4">
        {{ t('auth.forgot.sent') }}
      </v-alert>
      <template v-else>
        <p class="mb-4">
          {{ t('auth.forgot.intro') }}
        </p>
        <ProblemAlert :error="error" :known-fields="['email']" />
        <v-text-field
          v-model="email"
          name="email"
          type="email"
          autocomplete="username"
          :label="t('auth.fields.email')"
          :error-messages="fields.email"
          autofocus
        />
      </template>
    </v-card-text>
    <v-card-actions class="px-4 pb-4">
      <v-btn variant="text" :to="{ name: 'login' }">
        {{ t('auth.forgot.back') }}
      </v-btn>
      <v-spacer />
      <v-btn v-if="!sent" type="submit" color="primary" variant="flat" :loading="busy">
        {{ t('auth.forgot.submit') }}
      </v-btn>
    </v-card-actions>
  </v-form>
</template>
