<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { fieldErrors } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { safeRedirect } from '../../router/guards'
import { useSession } from '../../stores/session'
import { asApiError } from '../../utils/errors'

const { t } = useI18n()
const session = useSession()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const remember = ref(false)
const busy = ref(false)
const error = ref<ApiError | null>(null)
const fields = computed(() => fieldErrors(error.value))

async function submit(): Promise<void> {
  if (busy.value)
    return
  busy.value = true
  error.value = null
  try {
    const outcome = await session.login(email.value, password.value, remember.value)
    if (outcome === 'two-factor')
      await router.push({ name: 'two-factor', query: route.query })
    else
      await router.replace(safeRedirect(route.query.redirect))
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
      {{ t('auth.login.title') }}
    </v-card-title>
    <v-card-text>
      <ProblemAlert :error="error" :known-fields="['email', 'password']" />
      <v-text-field
        v-model="email"
        name="email"
        type="email"
        autocomplete="username"
        :label="t('auth.fields.email')"
        :error-messages="fields.email"
        autofocus
      />
      <v-text-field
        v-model="password"
        name="password"
        type="password"
        autocomplete="current-password"
        :label="t('auth.fields.password')"
        :error-messages="fields.password"
      />
      <v-checkbox v-model="remember" name="remember" :label="t('auth.login.remember')" hide-details />
    </v-card-text>
    <v-card-actions class="px-4 pb-4">
      <v-btn variant="text" :to="{ name: 'forgot-password' }">
        {{ t('auth.login.forgot') }}
      </v-btn>
      <v-spacer />
      <v-btn type="submit" color="primary" variant="flat" :loading="busy">
        {{ t('auth.login.submit') }}
      </v-btn>
    </v-card-actions>
  </v-form>
</template>
