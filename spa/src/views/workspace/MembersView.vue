<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Invitation } from '../../api/types'
import type { WorkspaceRole } from '../../utils/roles'
import { mdiAccountMinus, mdiEmailRemove } from '@mdi/js'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, fieldErrors, unwrap, useCursorList } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'
import { ROLES } from '../../utils/roles'

const { t } = useI18n()
const route = useRoute()
const session = useSession()
const can = useCan()
const { notify } = useNotify()
const failure = useApiFailure()

const slug = computed(() => String(route.params.workspace))

const list = useCursorList(cursor => bodyOf(api.GET('/workspaces/{workspace}/members', {
  params: {
    path: { workspace: slug.value },
    query: { cursor: cursor ?? undefined },
  },
})))
type Member = (typeof list.items.value)[number]

// Pending invitations; only managers see them.
const invitations = useCursorList(cursor => bodyOf(api.GET('/workspaces/{workspace}/invitations', {
  params: {
    path: { workspace: slug.value },
    query: { cursor: cursor ?? undefined },
  },
})))
const revoking = ref<Invitation | null>(null)
const revokeBusy = ref(false)
const revokeError = ref<ApiError | null>(null)
const confirmRevoke = computed({
  get: () => revoking.value !== null,
  set: (open) => {
    if (!open)
      revoking.value = null
  },
})

const roleItems = computed(() => ROLES.map(role => ({
  value: role,
  title: t(`roles.${role}`),
})))

const rowErrors = reactive<Record<number, ApiError | undefined>>({})
const rowBusy = reactive<Record<number, boolean | undefined>>({})

const email = ref('')
const role = ref<WorkspaceRole>('viewer')
const adding = ref(false)
const addError = ref<ApiError | null>(null)
const addFields = computed(() => fieldErrors(addError.value))

const removing = ref<Member | null>(null)
const confirmRemove = computed({
  get: () => removing.value !== null,
  set: (open) => {
    if (!open)
      removing.value = null
  },
})

function reset(): void {
  for (const key of Object.keys(rowErrors))
    delete rowErrors[Number(key)]
  revokeError.value = null
  void list.reload()
  if (can('manageMembers'))
    void invitations.reload()
}

watch(slug, reset)
if (can('viewMembers'))
  void list.loadMore()
if (can('manageMembers'))
  void invitations.loadMore()

function formatDate(value: string): string {
  return new Date(value).toLocaleString()
}

async function revoke(): Promise<void> {
  const invitation = revoking.value
  if (!invitation || revokeBusy.value)
    return
  revokeBusy.value = true
  revokeError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/invitations/{invitation}', {
      params: {
        path: { workspace: slug.value, invitation: invitation.id },
      },
    }))
    invitations.items.value = invitations.items.value.filter(i => i.id !== invitation.id)
    notify(t('members.invitations.revoked', { email: invitation.email }))
  }
  catch (e) {
    revokeError.value = failure(e)
  }
  finally {
    revoking.value = null
    revokeBusy.value = false
  }
}

async function changeRole(member: Member, next: WorkspaceRole | null): Promise<void> {
  if (next === null || next === member.role || rowBusy[member.id])
    return
  rowBusy[member.id] = true
  rowErrors[member.id] = undefined
  try {
    const updated = await unwrap(api.PATCH('/workspaces/{workspace}/members/{user}', {
      params: {
        path: { workspace: slug.value, user: member.id },
      },
      body: { role: next },
    }))
    list.items.value = list.items.value.map(m => (m.id === updated.id
      ? updated
      : m))
    notify(t('members.roleChanged', {
      name: updated.name,
      role: t(`roles.${updated.role}`),
    }))
    if (updated.id === session.user?.id)
      await session.load()
  }
  catch (e) {
    rowErrors[member.id] = failure(e)
  }
  finally {
    rowBusy[member.id] = undefined
  }
}

async function remove(): Promise<void> {
  const member = removing.value
  if (!member || rowBusy[member.id])
    return
  rowBusy[member.id] = true
  rowErrors[member.id] = undefined
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/members/{user}', {
      params: {
        path: { workspace: slug.value, user: member.id },
      },
    }))
    removing.value = null
    list.items.value = list.items.value.filter(m => m.id !== member.id)
    notify(t('members.removed', { name: member.name }))
    if (member.id === session.user?.id)
      await session.load()
  }
  catch (e) {
    rowErrors[member.id] = failure(e)
    removing.value = null
  }
  finally {
    rowBusy[member.id] = undefined
  }
}

async function invite(): Promise<void> {
  if (adding.value || email.value.trim() === '')
    return
  adding.value = true
  addError.value = null
  try {
    const sent = await unwrap(api.POST('/workspaces/{workspace}/members', {
      params: {
        path: { workspace: slug.value },
      },
      body: {
        email: email.value.trim(),
        role: role.value,
      },
    }))
    email.value = ''
    notify(t('members.invite.sent', { email: sent.email }))
    await invitations.reload()
  }
  catch (e) {
    addError.value = failure(e)
  }
  finally {
    adding.value = false
  }
}
</script>

<template>
  <h1 class="text-h5 mb-4">
    {{ t('members.title') }}
  </h1>

  <EmptyState v-if="!can('viewMembers')" :title="t('common.noAccess')" />

  <template v-else>
    <v-card v-if="can('manageMembers')" class="mb-4" max-width="960">
      <v-card-title tag="h2" class="text-h6">
        {{ t('members.invite.title') }}
      </v-card-title>
      <v-form @submit.prevent="invite">
        <v-card-text>
          <p class="mb-4">
            {{ t('members.invite.hint') }}
          </p>
          <ProblemAlert :error="addError" :known-fields="['email', 'role']" />
          <div class="d-flex flex-wrap ga-4">
            <v-text-field
              v-model="email"
              name="member_email"
              type="email"
              :label="t('members.invite.email')"
              :error-messages="addFields.email"
              class="flex-grow-1"
            />
            <v-select
              v-model="role"
              data-test="add-role"
              :items="roleItems"
              :label="t('members.invite.role')"
              :error-messages="addFields.role"
              min-width="200"
            />
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn type="submit" color="primary" variant="flat" :loading="adding">
            {{ t('members.invite.submit') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>

    <v-card v-if="can('manageMembers')" class="mb-4" max-width="960" data-test="invitations">
      <v-card-title tag="h2" class="text-h6">
        {{ t('members.invitations.title') }}
      </v-card-title>
      <v-card-text>
        <ProblemAlert :error="invitations.error.value ?? revokeError" />
        <v-table v-if="invitations.items.value.length > 0" density="compact">
          <caption class="d-sr-only">
            {{ t('members.invitations.title') }}
          </caption>
          <thead>
            <tr>
              <th scope="col">
                {{ t('members.email') }}
              </th>
              <th scope="col">
                {{ t('members.role') }}
              </th>
              <th scope="col">
                {{ t('members.invitations.expires') }}
              </th>
              <th scope="col">
                {{ t('members.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="invitation in invitations.items.value" :key="invitation.id" :data-test="`invitation-${invitation.id}`">
              <td>{{ invitation.email }}</td>
              <td>{{ t(`roles.${invitation.role}`) }}</td>
              <td>
                {{ formatDate(invitation.expires_at) }}
                <v-chip v-if="invitation.expired" size="x-small" label color="warning" class="ml-1">
                  {{ t('members.invitations.expired') }}
                </v-chip>
              </td>
              <td>
                <v-btn
                  :data-test="`revoke-${invitation.id}`"
                  :icon="mdiEmailRemove"
                  :aria-label="`${t('members.invitations.revoke')}: ${invitation.email}`"
                  :disabled="revokeBusy"
                  variant="text"
                  color="error"
                  size="small"
                  @click="revoking = invitation"
                />
              </td>
            </tr>
          </tbody>
        </v-table>
        <p v-else-if="!invitations.loading.value && !invitations.error.value" class="text-medium-emphasis">
          {{ t('members.invitations.empty') }}
        </p>
        <div v-if="invitations.hasMore.value && invitations.items.value.length > 0" class="mt-2">
          <v-btn variant="tonal" :loading="invitations.loading.value" @click="invitations.loadMore()">
            {{ t('common.loadMore') }}
          </v-btn>
        </div>
      </v-card-text>
    </v-card>

    <ProblemAlert :error="list.error.value" />

    <v-table max-width="960">
      <caption class="d-sr-only">
        {{ t('members.list') }}
      </caption>
      <thead>
        <tr>
          <th scope="col">
            {{ t('members.name') }}
          </th>
          <th scope="col">
            {{ t('members.email') }}
          </th>
          <th scope="col">
            {{ t('members.role') }}
          </th>
          <th v-if="can('manageMembers')" scope="col">
            {{ t('members.actions') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <template v-for="member in list.items.value" :key="member.id">
          <tr>
            <td>
              {{ member.name }}
              <v-chip v-if="member.id === session.user?.id" size="x-small" label class="ml-1">
                {{ t('members.you') }}
              </v-chip>
            </td>
            <td>{{ member.email }}</td>
            <td>
              <v-select
                v-if="can('manageMembers')"
                :data-test="`role-${member.id}`"
                :model-value="member.role"
                :items="roleItems"
                :label="t('members.roleOf', { name: member.name })"
                :disabled="rowBusy[member.id] === true"
                density="compact"
                hide-details
                min-width="180"
                @update:model-value="changeRole(member, $event)"
              />
              <span v-else>{{ t(`roles.${member.role}`) }}</span>
            </td>
            <td v-if="can('manageMembers')">
              <v-btn
                :data-test="`remove-${member.id}`"
                :icon="mdiAccountMinus"
                :aria-label="`${t('members.remove')}: ${member.name}`"
                :disabled="rowBusy[member.id] === true"
                variant="text"
                color="error"
                size="small"
                @click="removing = member"
              />
            </td>
          </tr>
          <tr v-if="rowErrors[member.id]">
            <td colspan="4" :data-test="`row-error-${member.id}`">
              <ProblemAlert :error="rowErrors[member.id] ?? null" class="mt-2" />
            </td>
          </tr>
        </template>
      </tbody>
    </v-table>

    <EmptyState v-if="!list.loading.value && !list.error.value && list.items.value.length === 0" :title="t('members.empty')" />

    <div v-if="list.hasMore.value && list.items.value.length > 0" class="mt-4">
      <v-btn data-test="load-more" variant="tonal" :loading="list.loading.value" @click="list.loadMore()">
        {{ t('common.loadMore') }}
      </v-btn>
    </div>
  </template>

  <ConfirmDialog
    v-model="confirmRemove"
    :title="t('members.remove')"
    :text="t('members.removeConfirm', { name: removing?.name ?? '' })"
    :confirm-label="t('common.remove')"
    destructive
    @confirm="remove"
  />

  <ConfirmDialog
    v-model="confirmRevoke"
    :title="t('members.invitations.revoke')"
    :text="t('members.invitations.revokeConfirm', { email: revoking?.email ?? '' })"
    :confirm-label="t('members.invitations.revoke')"
    destructive
    @confirm="revoke"
  />
</template>
