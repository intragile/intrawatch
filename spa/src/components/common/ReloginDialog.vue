<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { fieldErrors } from '../../api'
import { reloginPrompt } from '../../auth/prompts'
import { useSession } from '../../stores/session'
import { asApiError } from '../../utils/errors'
import TwoFactorFields from '../auth/TwoFactorFields.vue'
import ProblemAlert from './ProblemAlert.vue'

const { t } = useI18n()
const session = useSession()
const router = useRouter()

const step = ref<'password' | 'two-factor'>('password')
const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const twoFactorFields = ref<InstanceType<typeof TwoFactorFields> | null>(null)

const open = reloginPrompt.open
const knownEmail = computed(() => session.user?.email ?? null)
const fields = computed(() => fieldErrors(error.value))
const twoFactorError = computed(() => fields.value.code ?? fields.value.recovery_code)

watch(open, (isOpen) => {
  if (!isOpen)
    return
  step.value = 'password'
  email.value = knownEmail.value ?? ''
  password.value = ''
  error.value = null
}, { immediate: true })

function finish(ok: boolean): void {
  password.value = ''
  reloginPrompt.settle(ok)
}

async function submit(): Promise<void> {
  if (busy.value)
    return
  busy.value = true
  error.value = null
  try {
    if (step.value === 'password') {
      const outcome = await session.login(email.value, password.value, false)
      if (outcome === 'two-factor') {
        step.value = 'two-factor'
        return
      }
    }
    else {
      const answer = twoFactorFields.value?.answer
      if (!answer)
        return
      await session.twoFactor(answer)
    }
    finish(true)
  }
  catch (e) {
    error.value = asApiError(e)
  }
  finally {
    busy.value = false
  }
}

/** Gives up on the waiting request and leaves for the full login screen. */
async function signInAgain(): Promise<void> {
  const redirect = router.currentRoute.value.fullPath
  finish(false)
  session.me = null
  await router.push({
    name: 'login',
    query: { redirect },
  })
}
</script>

<template>
  <v-dialog :model-value="open" persistent max-width="440">
    <v-card :title="t('auth.relogin.title')">
      <v-form @submit.prevent="submit">
        <v-card-text>
          <p class="mb-4">
            {{ t('auth.relogin.intro') }}
          </p>
          <ProblemAlert :error="error" :known-fields="['email', 'password', 'code', 'recovery_code']" />
          <template v-if="step === 'password'">
            <v-text-field
              v-model="email"
              name="relogin_email"
              type="email"
              autocomplete="username"
              :label="t('auth.fields.email')"
              :readonly="knownEmail !== null"
              :error-messages="fields.email"
            />
            <v-text-field
              v-model="password"
              name="relogin_password"
              type="password"
              autocomplete="current-password"
              :label="t('auth.fields.password')"
              :error-messages="fields.password"
              autofocus
            />
          </template>
          <template v-else>
            <p class="mb-4">
              {{ t('auth.twoFactor.intro') }}
            </p>
            <TwoFactorFields ref="twoFactorFields" :error="twoFactorError" />
          </template>
        </v-card-text>
        <v-card-actions>
          <v-btn variant="text" :disabled="busy" @click="signInAgain">
            {{ t('auth.relogin.leave') }}
          </v-btn>
          <v-spacer />
          <v-btn variant="text" :disabled="busy" @click="finish(false)">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn type="submit" color="primary" variant="flat" :loading="busy">
            {{ t('auth.relogin.submit') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </v-dialog>
</template>
