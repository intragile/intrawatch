<script setup lang="ts">
import type { ApiError } from '../../api'
import { mdiContentCopy, mdiDownload, mdiShieldCheck, mdiShieldOffOutline } from '@mdi/js'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '../../api'
import * as fortify from '../../auth/fortify'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useNotify } from '../../composables/useNotify'
import { useSession } from '../../stores/session'
import { asApiError } from '../../utils/errors'

type Phase = 'idle' | 'setup' | 'codes'

const { t } = useI18n()
const session = useSession()
const { notify } = useNotify()

const phase = ref<Phase>('idle')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const qrSvg = ref<string | null>(null)
const secretKey = ref<string | null>(null)
const code = ref('')
// Recovery codes live only in this component and are dropped when the user is done.
const codes = ref<string[]>([])
// Set when the recovery codes of a confirmed setup could not be fetched.
const codesError = ref<ApiError | null>(null)
const confirmDisable = ref(false)
const confirmRegenerate = ref(false)

const user = computed(() => session.user)
const enabled = computed(() => user.value?.two_factor_enabled === true)
const fields = computed(() => fieldErrors(error.value))
const qrSrc = computed(() => (qrSvg.value
  ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrSvg.value)}`
  : null))

async function run(action: () => Promise<void>): Promise<void> {
  if (busy.value)
    return
  busy.value = true
  error.value = null
  try {
    await action()
  }
  catch (e) {
    error.value = asApiError(e)
  }
  finally {
    busy.value = false
  }
}

function enable(): Promise<void> {
  return run(async () => {
    await fortify.enableTwoFactor()
    const [svg, secret] = await Promise.all([
      fortify.twoFactorQrCode(),
      fortify.twoFactorSecretKey(),
    ])
    qrSvg.value = svg
    secretKey.value = secret
    code.value = ''
    phase.value = 'setup'
  })
}

function confirm(): Promise<void> {
  const value = code.value.replace(/\s+/g, '')
  if (value === '')
    return Promise.resolve()
  return run(async () => {
    await fortify.confirmTwoFactor(value)
    // Two-factor is confirmed from here on; failures below must not return to the setup step.
    qrSvg.value = null
    secretKey.value = null
    code.value = ''
    codes.value = []
    phase.value = 'codes'
    await fetchCodes()
    await refreshSession()
  })
}

/** Loads the recovery codes; a failure is kept apart so it can be retried in place. */
async function fetchCodes(): Promise<void> {
  codesError.value = null
  try {
    codes.value = await fortify.recoveryCodes()
  }
  catch (e) {
    codesError.value = asApiError(e)
  }
}

async function refreshSession(): Promise<void> {
  try {
    await session.load()
  }
  catch {
    // The next focus refresh picks the new state up.
  }
}

function retryCodes(): Promise<void> {
  return run(fetchCodes)
}

function cancelSetup(): Promise<void> {
  // Removes the unconfirmed secret so a half-finished setup leaves nothing behind.
  return run(async () => {
    await fortify.disableTwoFactor()
    qrSvg.value = null
    secretKey.value = null
    phase.value = 'idle'
  })
}

async function regenerate(): Promise<void> {
  await run(async () => {
    await fortify.regenerateRecoveryCodes()
    phase.value = 'codes'
    codes.value = []
    await fetchCodes()
  })
  confirmRegenerate.value = false
}

async function disable(): Promise<void> {
  await run(async () => {
    await fortify.disableTwoFactor()
    await session.load()
    notify(t('account.twoFactor.disabledNotice'))
  })
  confirmDisable.value = false
}

function finishCodes(): void {
  codes.value = []
  codesError.value = null
  phase.value = 'idle'
  void refreshSession()
}

async function copyCodes(): Promise<void> {
  try {
    await navigator.clipboard.writeText(codes.value.join('\n'))
    notify(t('common.copied'))
  }
  catch {
    notify(t('common.copyFailed'), 'error')
  }
}

function downloadCodes(): void {
  try {
    const blob = new Blob([`${codes.value.join('\n')}\n`], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'intrawatch-recovery-codes.txt'
    link.click()
    URL.revokeObjectURL(url)
  }
  catch {
    notify(t('common.downloadFailed'), 'error')
  }
}
</script>

<template>
  <h1 class="text-h5 mb-4">
    {{ t('account.title') }}
  </h1>

  <v-card class="mb-4" max-width="720">
    <v-card-title as="h2" class="text-h6">
      {{ t('account.profile') }}
    </v-card-title>
    <v-card-text>
      <v-text-field :model-value="user?.name ?? ''" :label="t('auth.fields.name')" readonly variant="plain" />
      <v-text-field :model-value="user?.email ?? ''" :label="t('auth.fields.email')" readonly variant="plain" />
    </v-card-text>
  </v-card>

  <v-card max-width="720">
    <v-card-title as="h2" class="text-h6 d-flex align-center ga-2">
      {{ t('account.twoFactor.title') }}
      <v-chip
        :color="enabled ? 'success' : 'grey'"
        :prepend-icon="enabled ? mdiShieldCheck : mdiShieldOffOutline"
        size="small"
        label
      >
        {{ enabled ? t('account.twoFactor.on') : t('account.twoFactor.off') }}
      </v-chip>
    </v-card-title>
    <v-card-text>
      <ProblemAlert :error="error" :known-fields="phase === 'setup' ? ['code'] : []" />

      <template v-if="phase === 'codes'">
        <ProblemAlert :error="codesError" />
        <v-btn
          v-if="codesError"
          data-test="codes-retry"
          class="mb-4"
          variant="tonal"
          :loading="busy"
          @click="retryCodes"
        >
          {{ t('account.twoFactor.showCodes') }}
        </v-btn>
        <v-alert type="warning" variant="tonal" class="mb-4">
          {{ t('account.twoFactor.codesIntro') }}
        </v-alert>
        <ul v-if="!codesError" class="recovery-codes mb-4" :aria-label="t('account.twoFactor.codesTitle')">
          <li v-for="item in codes" :key="item">
            <code>{{ item }}</code>
          </li>
        </ul>
        <div class="d-flex flex-wrap ga-2">
          <v-btn variant="tonal" :prepend-icon="mdiContentCopy" :disabled="codes.length === 0" @click="copyCodes">
            {{ t('common.copy') }}
          </v-btn>
          <v-btn variant="tonal" :prepend-icon="mdiDownload" :disabled="codes.length === 0" @click="downloadCodes">
            {{ t('common.download') }}
          </v-btn>
          <v-spacer />
          <v-btn data-test="codes-done" color="primary" variant="flat" @click="finishCodes">
            {{ t('account.twoFactor.codesDone') }}
          </v-btn>
        </div>
      </template>

      <v-form v-else-if="phase === 'setup'" @submit.prevent="confirm">
        <p class="mb-4">
          {{ t('account.twoFactor.scan') }}
        </p>
        <img
          v-if="qrSrc"
          :src="qrSrc"
          :alt="t('account.twoFactor.qrAlt')"
          width="192"
          height="192"
          class="qr mb-4"
        >
        <p v-if="secretKey" class="mb-4">
          {{ t('account.twoFactor.secret') }}
          <code class="secret">{{ secretKey }}</code>
        </p>
        <v-text-field
          v-model="code"
          name="totp_code"
          :label="t('auth.twoFactor.code')"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="10"
          :error-messages="fields.code"
          max-width="280"
          autofocus
        />
        <div class="d-flex ga-2">
          <v-btn variant="text" :disabled="busy" @click="cancelSetup">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn type="submit" color="primary" variant="flat" :loading="busy">
            {{ t('account.twoFactor.confirm') }}
          </v-btn>
        </div>
      </v-form>

      <template v-else-if="enabled">
        <p class="mb-4">
          {{ t('account.twoFactor.enabledText') }}
        </p>
        <div class="d-flex flex-wrap ga-2">
          <v-btn variant="tonal" :disabled="busy" @click="confirmRegenerate = true">
            {{ t('account.twoFactor.regenerate') }}
          </v-btn>
          <v-btn data-test="2fa-disable" color="error" variant="tonal" :disabled="busy" @click="confirmDisable = true">
            {{ t('account.twoFactor.disable') }}
          </v-btn>
        </div>
      </template>

      <template v-else>
        <p class="mb-4">
          {{ t('account.twoFactor.disabledText') }}
        </p>
        <v-btn data-test="2fa-enable" color="primary" variant="flat" :loading="busy" @click="enable">
          {{ t('account.twoFactor.enable') }}
        </v-btn>
      </template>
    </v-card-text>
  </v-card>

  <ConfirmDialog
    v-model="confirmRegenerate"
    :title="t('account.twoFactor.regenerate')"
    :text="t('account.twoFactor.regenerateConfirm')"
    :confirm-label="t('account.twoFactor.regenerate')"
    :loading="busy"
    @confirm="regenerate"
  />
  <ConfirmDialog
    v-model="confirmDisable"
    :title="t('account.twoFactor.disable')"
    :text="t('account.twoFactor.disableConfirm')"
    :confirm-label="t('account.twoFactor.disable')"
    destructive
    :loading="busy"
    @confirm="disable"
  />
</template>

<style scoped>
.recovery-codes {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
  gap: 0.25rem 1rem;
  padding: 0;
}

.qr {
  background: #fff;
  padding: 8px;
  border-radius: 4px;
}

.secret {
  word-break: break-all;
}
</style>
