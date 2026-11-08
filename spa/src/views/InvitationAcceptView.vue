<script setup lang="ts">
import type { ApiError } from '../api'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, unwrap } from '../api'
import ProblemAlert from '../components/common/ProblemAlert.vue'
import { useApiFailure } from '../composables/useApiFailure'
import { useNotify } from '../composables/useNotify'
import { useSession } from '../stores/session'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const { notify } = useNotify()
const failure = useApiFailure()

const token = computed(() => String(route.params.token ?? ''))
const busy = ref(false)
/** Any 422: unknown, expired, revoked, used or addressed to someone else all look the same. */
const invalid = ref(false)
const error = ref<ApiError | null>(null)

async function accept(): Promise<void> {
  if (busy.value)
    return
  busy.value = true
  invalid.value = false
  error.value = null
  try {
    const joined = await unwrap(api.POST('/invitations/accept', {
      body: { token: token.value },
    }))
    await session.load()
    notify(t('invitation.accepted', { name: joined.name }))
    await router.replace({
      name: 'ws.monitors',
      params: { workspace: joined.slug },
    })
  }
  catch (e) {
    const problem = failure(e)
    if (problem.status === 422)
      invalid.value = true
    else
      error.value = problem
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <v-card-title tag="h1" class="text-h6">
    {{ t('invitation.title') }}
  </v-card-title>
  <v-card-text>
    <v-alert
      v-if="invalid"
      data-test="invitation-error"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ t('invitation.invalid') }}
    </v-alert>
    <ProblemAlert :error="error" />
    <p>{{ t('invitation.intro', { email: session.user?.email ?? '' }) }}</p>
  </v-card-text>
  <v-card-actions>
    <v-btn data-test="invitation-home" variant="text" :to="{ name: 'home' }">
      {{ t('invitation.home') }}
    </v-btn>
    <v-spacer />
    <v-btn
      data-test="accept-invitation"
      color="primary"
      variant="flat"
      :loading="busy"
      :disabled="invalid"
      @click="accept"
    >
      {{ t('invitation.accept') }}
    </v-btn>
  </v-card-actions>
</template>
