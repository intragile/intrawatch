<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { api, fieldErrors } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useNotify } from '../../composables/useNotify'
import { PASSWORD_MIN, usePasswordRules } from '../../composables/usePasswordRules'
import { useSession } from '../../stores/session'
import { asApiError } from '../../utils/errors'

interface FormRef { validate: () => Promise<{ valid: boolean }> }

const OWNER_FIELDS = ['name', 'email', 'password']

const { t } = useI18n()
const router = useRouter()
const session = useSession()
const { notify } = useNotify()

function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
  }
  catch {
    return 'UTC'
  }
}

function supportedTimezones(fallback: string): string[] {
  try {
    const zones = Intl.supportedValuesOf('timeZone')
    return zones.includes(fallback)
      ? zones
      : [fallback, ...zones]
  }
  catch {
    return [fallback]
  }
}

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  workspace_name: '',
  timezone: browserTimezone(),
})
const timezones = supportedTimezones(form.timezone)

const step = ref<1 | 2>(1)
const busy = ref(false)
const error = ref<ApiError | null>(null)
const ownerForm = ref<FormRef | null>(null)
const workspaceForm = ref<FormRef | null>(null)
const fields = computed(() => fieldErrors(error.value))
const rules = usePasswordRules(computed(() => form.password))

async function next(): Promise<void> {
  if ((await ownerForm.value?.validate())?.valid)
    step.value = 2
}

/** Applies the chosen timezone; setup itself does not take one. A failure is not fatal. */
async function applyTimezone(): Promise<void> {
  const workspace = session.workspaces[0]
  if (!workspace || !form.timezone || workspace.timezone === form.timezone)
    return
  try {
    await api.PATCH('/workspaces/{workspace}', {
      params: {
        path: { workspace: workspace.slug },
      },
      body: { name: workspace.name, timezone: form.timezone },
    })
    await session.load()
  }
  catch {
    // The owner can still change it in the workspace settings.
  }
}

async function submit(): Promise<void> {
  if (busy.value || !(await workspaceForm.value?.validate())?.valid)
    return
  busy.value = true
  error.value = null
  try {
    const { error: failure } = await api.POST('/setup', {
      body: {
        name: form.name,
        email: form.email,
        password: form.password,
        workspace_name: form.workspace_name,
      },
    })
    if (failure !== undefined)
      throw failure
    session.setupRequired = false
    await session.load()
    await applyTimezone()
    notify(t('auth.setup.done'))
    const slug = session.homeWorkspace()
    await router.replace(slug === null
      ? { name: 'home' }
      : {
          name: 'ws.monitors',
          params: { workspace: slug },
        })
  }
  catch (e) {
    error.value = asApiError(e)
    if (Object.keys(fields.value).some(key => OWNER_FIELDS.includes(key)))
      step.value = 1
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <v-card-title tag="h1" class="text-h6">
    {{ t('auth.setup.title') }}
  </v-card-title>
  <v-card-subtitle>
    {{ t('auth.setup.step', { step, total: 2 }) }} · {{ step === 1 ? t('auth.setup.owner') : t('auth.setup.workspace') }}
  </v-card-subtitle>

  <v-form v-if="step === 1" ref="ownerForm" @submit.prevent="next">
    <v-card-text>
      <p class="mb-4">
        {{ t('auth.setup.intro') }}
      </p>
      <ProblemAlert :error="error" :known-fields="[...OWNER_FIELDS, 'workspace_name']" />
      <v-text-field
        v-model="form.name"
        name="name"
        autocomplete="name"
        :label="t('auth.fields.name')"
        :rules="[rules.required]"
        :error-messages="fields.name"
        autofocus
      />
      <v-text-field
        v-model="form.email"
        name="email"
        type="email"
        autocomplete="username"
        :label="t('auth.fields.email')"
        :rules="[rules.required, rules.email]"
        :error-messages="fields.email"
      />
      <v-text-field
        v-model="form.password"
        name="password"
        type="password"
        autocomplete="new-password"
        :label="t('auth.fields.password')"
        :hint="t('validation.passwordMin', { min: PASSWORD_MIN })"
        :rules="[rules.required, rules.min]"
        :error-messages="fields.password"
      />
      <v-text-field
        v-model="form.password_confirmation"
        name="password_confirmation"
        type="password"
        autocomplete="new-password"
        :label="t('auth.fields.passwordConfirmation')"
        :rules="[rules.required, rules.matches]"
      />
    </v-card-text>
    <v-card-actions class="px-4 pb-4">
      <v-spacer />
      <v-btn type="submit" color="primary" variant="flat">
        {{ t('common.next') }}
      </v-btn>
    </v-card-actions>
  </v-form>

  <v-form v-else ref="workspaceForm" @submit.prevent="submit">
    <v-card-text>
      <ProblemAlert :error="error" :known-fields="[...OWNER_FIELDS, 'workspace_name']" />
      <v-text-field
        v-model="form.workspace_name"
        name="workspace_name"
        :label="t('auth.setup.workspaceName')"
        :hint="t('auth.setup.workspaceHint')"
        persistent-hint
        :rules="[rules.required]"
        :error-messages="fields.workspace_name"
        maxlength="120"
        class="mb-2"
        autofocus
      />
      <v-autocomplete
        v-model="form.timezone"
        name="timezone"
        :items="timezones"
        :label="t('auth.setup.timezone')"
        :hint="t('auth.setup.timezoneHint')"
        persistent-hint
      />
    </v-card-text>
    <v-card-actions class="px-4 pb-4">
      <v-btn variant="text" :disabled="busy" @click="step = 1">
        {{ t('common.back') }}
      </v-btn>
      <v-spacer />
      <v-btn data-test="setup-submit" type="submit" color="primary" variant="flat" :loading="busy">
        {{ t('auth.setup.submit') }}
      </v-btn>
    </v-card-actions>
  </v-form>
</template>
