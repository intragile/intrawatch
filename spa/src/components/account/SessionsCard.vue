<script setup lang="ts">
import type { ApiError } from '../../api'
import type { UserSession } from '../../api/types'
import { mdiLogout, mdiLogoutVariant, mdiMonitorCellphone } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, fieldErrors, unwrap } from '../../api'
import { useNotify } from '../../composables/useNotify'
import { asApiError } from '../../utils/errors'
import { relativeTime } from '../../utils/relativeTime'
import ConfirmDialog from '../common/ConfirmDialog.vue'
import ProblemAlert from '../common/ProblemAlert.vue'

const { t, locale } = useI18n()
const { notify } = useNotify()

const sessions = ref<UserSession[]>([])
const loading = ref(false)
const loadError = ref<ApiError | null>(null)
const now = ref(Date.now())

const dialog = ref(false)
const password = ref('')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const fields = computed(() => fieldErrors(error.value))
const hasOthers = computed(() => sessions.value.some(s => !s.current))

// The one session the confirm dialog is about to end.
const target = ref<UserSession | null>(null)
const ending = ref(false)
const endError = ref<ApiError | null>(null)
const confirmOpen = computed({
  get: () => target.value !== null,
  set: (open: boolean) => {
    if (!open) {
      target.value = null
      endError.value = null
    }
  },
})

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    sessions.value = await unwrap<UserSession[]>(api.GET('/me/sessions'))
    now.value = Date.now()
  }
  catch (e) {
    loadError.value = asApiError(e)
  }
  finally {
    loading.value = false
  }
}

function device(session: UserSession): string {
  const parts = [session.browser, session.platform].filter((part): part is string => part !== null)
  if (parts.length > 0)
    return parts.join(' · ')
  return session.user_agent ?? t('account.sessions.unknownDevice')
}

function details(session: UserSession): string {
  const when = t('account.sessions.lastActive', {
    when: relativeTime(session.last_active_at, now.value, locale.value),
  })
  return session.ip_address
    ? `${session.ip_address} · ${when}`
    : when
}

watch(dialog, (open) => {
  if (open) {
    password.value = ''
    error.value = null
  }
})

async function signOutOthers(): Promise<void> {
  if (busy.value || password.value === '')
    return
  busy.value = true
  error.value = null
  try {
    await unwrap<void>(api.POST('/me/sessions/sign-out-others', {
      body: { password: password.value },
    }))
    password.value = ''
    dialog.value = false
    notify(t('account.sessions.signOutOthersDone'))
    await load()
  }
  catch (e) {
    error.value = asApiError(e)
  }
  finally {
    busy.value = false
  }
}

async function signOutOne(): Promise<void> {
  const session = target.value
  if (ending.value || !session)
    return
  ending.value = true
  endError.value = null
  try {
    await unwrap<void>(api.DELETE('/me/sessions/{id}', {
      params: {
        path: { id: session.id },
      },
    }))
  }
  catch (e) {
    const failure = asApiError(e)
    // A 404 means the session has already ended (expired or signed out elsewhere): the goal is met.
    if (failure.status !== 404) {
      endError.value = failure
      ending.value = false
      return
    }
  }
  ending.value = false
  confirmOpen.value = false
  notify(t('account.sessions.signOutOneDone', {
    device: device(session),
  }))
  await load()
}

onMounted(load)

defineExpose({ load })
</script>

<template>
  <v-card max-width="720">
    <v-card-title tag="h2" class="text-h6">
      {{ t('account.sessions.title') }}
    </v-card-title>
    <v-card-text>
      <p class="mb-2">
        {{ t('account.sessions.intro') }}
      </p>
      <ProblemAlert :error="loadError" />
      <v-progress-linear v-if="loading && sessions.length === 0" indeterminate />
      <v-list v-if="sessions.length > 0" class="py-0" data-test="sessions">
        <v-list-item
          v-for="session in sessions"
          :key="session.id"
          :prepend-icon="mdiMonitorCellphone"
          :title="device(session)"
          :subtitle="details(session)"
          class="px-0"
        >
          <template #append>
            <v-chip v-if="session.current" color="primary" size="small" label>
              {{ t('account.sessions.thisDevice') }}
            </v-chip>
            <v-btn
              v-else
              data-test="sign-out-one"
              variant="text"
              color="error"
              size="small"
              :prepend-icon="mdiLogout"
              :aria-label="t('account.sessions.signOutOneLabel', { device: device(session) })"
              @click="target = session"
            >
              {{ t('account.sessions.signOutOne') }}
            </v-btn>
          </template>
        </v-list-item>
      </v-list>
      <p v-if="!loading && !loadError && !hasOthers" class="text-medium-emphasis mt-2">
        {{ t('account.sessions.noOthers') }}
      </p>
      <v-btn
        data-test="sign-out-others"
        class="mt-4"
        variant="tonal"
        color="error"
        :prepend-icon="mdiLogoutVariant"
        :disabled="loading || !!loadError"
        @click="dialog = true"
      >
        {{ t('account.sessions.signOutOthers') }}
      </v-btn>
    </v-card-text>
  </v-card>

  <ConfirmDialog
    v-model="confirmOpen"
    :title="t('account.sessions.signOutOneTitle')"
    :confirm-label="t('account.sessions.signOutOne')"
    :loading="ending"
    destructive
    @confirm="signOutOne"
  >
    <ProblemAlert :error="endError" />
    {{ target ? t('account.sessions.signOutOneText', { device: device(target) }) : '' }}
  </ConfirmDialog>

  <v-dialog v-model="dialog" max-width="440" :persistent="busy">
    <v-card :title="t('account.sessions.signOutOthers')">
      <v-form @submit.prevent="signOutOthers">
        <v-card-text>
          <p class="mb-4">
            {{ t('account.sessions.signOutOthersIntro') }}
          </p>
          <ProblemAlert :error="error" :known-fields="['password']" />
          <v-text-field
            v-model="password"
            name="sign_out_password"
            type="password"
            autocomplete="current-password"
            :label="t('auth.fields.password')"
            :error-messages="fields.password"
            autofocus
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="busy" @click="dialog = false">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn data-test="sign-out-confirm" type="submit" color="error" variant="flat" :loading="busy" :disabled="password === ''">
            {{ t('account.sessions.signOutConfirm') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </v-dialog>
</template>
