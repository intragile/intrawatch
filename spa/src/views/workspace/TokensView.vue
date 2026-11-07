<script setup lang="ts">
import type { ApiError } from '../../api'
import type { TokenAbility } from '../../utils/tokenAbilities'
import { mdiClockAlertOutline, mdiContentCopy, mdiKeyRemove } from '@mdi/js'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, fieldErrors, unwrap, useCursorList } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useNotify } from '../../composables/useNotify'
import { bodyOf } from '../../utils/cursorPage'
import { ABILITY_LABEL_KEYS, TOKEN_ABILITIES } from '../../utils/tokenAbilities'
import { parseToken } from './rows'

const { t } = useI18n()
const route = useRoute()
const { notify } = useNotify()
const failure = useApiFailure()

const slug = computed(() => String(route.params.workspace))

const list = useCursorList(async (cursor) => {
  const result = await bodyOf(api.GET('/workspaces/{workspace}/tokens', {
    params: {
      path: { workspace: slug.value },
      query: { cursor: cursor ?? undefined },
    },
  }))
  return {
    data: result.data.map(parseToken),
    meta: result.meta,
  }
})
type TokenRow = (typeof list.items.value)[number]

const name = ref('')
const abilities = ref<TokenAbility[]>([])
const expiresAt = ref('')
const creating = ref(false)
const createError = ref<ApiError | null>(null)
const createFields = computed(() => fieldErrors(createError.value))
const abilitiesMessage = computed(() => Object.entries(createFields.value)
  .filter(([key]) => key === 'abilities' || key.startsWith('abilities.'))
  .map(([, message]) => message)
  .join(' '))

// The plaintext lives only here: it is dropped on close and never reaches a store, the URL or storage.
const plaintext = ref<string | null>(null)
const plaintextOpen = computed({
  get: () => plaintext.value !== null,
  set: (open) => {
    if (!open)
      plaintext.value = null
  },
})
onBeforeUnmount(() => {
  plaintext.value = null
})

const revoking = ref<TokenRow | null>(null)
const revokeError = ref<ApiError | null>(null)
const revokeBusy = ref(false)
const confirmRevoke = computed({
  get: () => revoking.value !== null,
  set: (open) => {
    if (!open) {
      revoking.value = null
      revokeError.value = null
    }
  },
})

watch(slug, () => {
  void list.reload()
})
void list.loadMore()

function isExpired(token: TokenRow): boolean {
  return token.expiresAt !== null && new Date(token.expiresAt).getTime() <= Date.now()
}

async function create(): Promise<void> {
  if (creating.value || name.value.trim() === '' || abilities.value.length === 0)
    return
  creating.value = true
  createError.value = null
  try {
    const created = await bodyOf(api.POST('/workspaces/{workspace}/tokens', {
      params: {
        path: { workspace: slug.value },
      },
      body: {
        name: name.value.trim(),
        abilities: [...abilities.value],
        ...(expiresAt.value === ''
          ? {}
          : {
              expires_at: new Date(expiresAt.value).toISOString(),
            }),
      },
    }))
    plaintext.value = created.token
    name.value = ''
    abilities.value = []
    expiresAt.value = ''
    await list.reload()
  }
  catch (e) {
    createError.value = failure(e)
  }
  finally {
    creating.value = false
  }
}

async function revoke(): Promise<void> {
  const token = revoking.value
  if (!token || revokeBusy.value)
    return
  revokeBusy.value = true
  revokeError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/tokens/{id}', {
      params: {
        path: { workspace: slug.value, id: token.id },
      },
    }))
    revoking.value = null
    notify(t('tokens.revoked'))
    await list.reloadLoaded()
  }
  catch (e) {
    revokeError.value = failure(e)
  }
  finally {
    revokeBusy.value = false
  }
}

async function copy(): Promise<void> {
  if (plaintext.value === null)
    return
  try {
    await navigator.clipboard.writeText(plaintext.value)
    notify(t('common.copied'))
  }
  catch {
    notify(t('common.copyFailed'), 'error')
  }
}
</script>

<template>
  <h1 class="text-h5 mb-2">
    {{ t('tokens.title') }}
  </h1>
  <p class="mb-4">
    {{ t('tokens.intro') }}
  </p>

  <v-card class="mb-4" max-width="960">
    <v-card-title as="h2" class="text-h6">
      {{ t('tokens.create.title') }}
    </v-card-title>
    <v-form @submit.prevent="create">
      <v-card-text>
        <ProblemAlert :error="createError" :known-fields="['name', 'expires_at']" />
        <div class="d-flex flex-wrap ga-4">
          <v-text-field
            v-model="name"
            name="token_name"
            :label="t('tokens.create.name')"
            :error-messages="createFields.name"
            maxlength="120"
            class="flex-grow-1"
          />
          <v-text-field
            v-model="expiresAt"
            name="token_expires_at"
            type="datetime-local"
            :label="t('tokens.create.expires')"
            :error-messages="createFields.expires_at"
          />
        </div>
        <fieldset class="abilities">
          <legend class="text-subtitle-2">
            {{ t('tokens.create.abilities') }}
          </legend>
          <div class="abilities-grid">
            <v-checkbox
              v-for="ability in TOKEN_ABILITIES"
              :key="ability"
              v-model="abilities"
              :value="ability"
              :label="t(`tokens.ability.${ABILITY_LABEL_KEYS[ability]}`)"
              density="compact"
              hide-details
            />
          </div>
          <p v-if="abilitiesMessage" class="text-error mt-2" role="alert">
            {{ abilitiesMessage }}
          </p>
          <p v-else-if="abilities.length === 0" class="text-medium-emphasis mt-2">
            {{ t('tokens.create.abilitiesRequired') }}
          </p>
        </fieldset>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn type="submit" color="primary" variant="flat" :loading="creating" :disabled="abilities.length === 0">
          {{ t('tokens.create.submit') }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </v-card>

  <ProblemAlert :error="list.error.value" />

  <v-table v-if="list.items.value.length > 0" max-width="960">
    <caption class="d-sr-only">
      {{ t('tokens.list') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('tokens.name') }}
        </th>
        <th scope="col">
          {{ t('tokens.abilitiesColumn') }}
        </th>
        <th scope="col">
          {{ t('tokens.lastUsed') }}
        </th>
        <th scope="col">
          {{ t('tokens.expiresColumn') }}
        </th>
        <th scope="col">
          {{ t('tokens.actions') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="token in list.items.value" :key="token.id">
        <td>{{ token.name }}</td>
        <td>
          <v-chip v-for="ability in token.abilities" :key="ability" size="x-small" label class="mr-1 mb-1">
            {{ ability }}
          </v-chip>
        </td>
        <td>
          <DateTime v-if="token.lastUsedAt" :value="token.lastUsedAt" />
          <span v-else>{{ t('tokens.neverUsed') }}</span>
        </td>
        <td>
          <template v-if="token.expiresAt">
            <DateTime :value="token.expiresAt" />
            <v-chip v-if="isExpired(token)" :prepend-icon="mdiClockAlertOutline" color="error" size="x-small" label class="ml-1">
              {{ t('tokens.expired') }}
            </v-chip>
          </template>
          <span v-else>{{ t('tokens.noExpiry') }}</span>
        </td>
        <td>
          <v-btn
            :data-test="`revoke-${token.id}`"
            :icon="mdiKeyRemove"
            :aria-label="`${t('tokens.revoke')}: ${token.name}`"
            variant="text"
            color="error"
            size="small"
            @click="revoking = token"
          />
        </td>
      </tr>
    </tbody>
  </v-table>

  <EmptyState v-else-if="!list.loading.value && !list.error.value" :title="t('tokens.empty')" />

  <div v-if="list.hasMore.value && list.items.value.length > 0" class="mt-4">
    <v-btn data-test="load-more" variant="tonal" :loading="list.loading.value" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>

  <v-dialog v-model="plaintextOpen" persistent max-width="560">
    <v-card :title="t('tokens.plain.title')">
      <v-card-text>
        <v-alert type="warning" variant="tonal" class="mb-4">
          {{ t('tokens.plain.warning') }}
        </v-alert>
        <v-text-field
          :model-value="plaintext ?? ''"
          name="token_plaintext"
          :label="t('tokens.plain.field')"
          readonly
          autocomplete="off"
          spellcheck="false"
        >
          <template #append-inner>
            <v-btn :icon="mdiContentCopy" :aria-label="t('common.copy')" variant="text" size="small" @click="copy" />
          </template>
        </v-text-field>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn data-test="token-done" color="primary" variant="flat" @click="plaintextOpen = false">
          {{ t('tokens.plain.done') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <ConfirmDialog
    v-model="confirmRevoke"
    :title="t('tokens.revokeTitle')"
    :confirm-label="t('tokens.revoke')"
    :loading="revokeBusy"
    destructive
    @confirm="revoke"
  >
    <ProblemAlert :error="revokeError" />
    {{ t('tokens.revokeConfirm', { name: revoking?.name ?? '' }) }}
  </ConfirmDialog>
</template>

<style scoped>
.abilities {
  border: 0;
  padding: 0;
  margin: 0;
}

.abilities-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
  gap: 0 1rem;
}
</style>
