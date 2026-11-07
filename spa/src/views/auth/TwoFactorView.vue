<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { fieldErrors } from '../../api'
import TwoFactorFields from '../../components/auth/TwoFactorFields.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { safeRedirect } from '../../router/guards'
import { useSession } from '../../stores/session'
import { asApiError } from '../../utils/errors'

const { t } = useI18n()
const session = useSession()
const route = useRoute()
const router = useRouter()

const fieldsRef = ref<InstanceType<typeof TwoFactorFields> | null>(null)
const busy = ref(false)
const error = ref<ApiError | null>(null)
const fields = computed(() => fieldErrors(error.value))

async function submit(): Promise<void> {
  const answer = fieldsRef.value?.answer
  if (busy.value || !answer)
    return
  busy.value = true
  error.value = null
  try {
    await session.twoFactor(answer)
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
    <v-card-title tag="h1" class="text-h6">
      {{ t('auth.twoFactor.title') }}
    </v-card-title>
    <v-card-text>
      <p class="mb-4">
        {{ t('auth.twoFactor.intro') }}
      </p>
      <ProblemAlert :error="error" :known-fields="['code', 'recovery_code']" />
      <TwoFactorFields ref="fieldsRef" :error="fields.code ?? fields.recovery_code" />
    </v-card-text>
    <v-card-actions class="px-4 pb-4">
      <v-btn variant="text" :to="{ name: 'login', query: route.query }">
        {{ t('common.back') }}
      </v-btn>
      <v-spacer />
      <v-btn type="submit" color="primary" variant="flat" :loading="busy">
        {{ t('auth.twoFactor.submit') }}
      </v-btn>
    </v-card-actions>
  </v-form>
</template>
