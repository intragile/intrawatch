<script setup lang="ts">
import type { ApiError } from '../../api'
import type { WorkspaceRole } from '../../utils/roles'
import { mdiAccountMinus } from '@mdi/js'
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
  void list.reload()
}

watch(slug, reset)
if (can('viewMembers'))
  void list.loadMore()

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

async function add(): Promise<void> {
  if (adding.value || email.value.trim() === '')
    return
  adding.value = true
  addError.value = null
  try {
    const created = await unwrap(api.POST('/workspaces/{workspace}/members', {
      params: {
        path: { workspace: slug.value },
      },
      body: {
        email: email.value.trim(),
        role: role.value,
      },
    }))
    email.value = ''
    notify(t('members.added', { name: created.name }))
    await list.reloadLoaded()
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
      <v-card-title as="h2" class="text-h6">
        {{ t('members.add.title') }}
      </v-card-title>
      <v-form @submit.prevent="add">
        <v-card-text>
          <p class="mb-4">
            {{ t('members.add.hint') }}
          </p>
          <ProblemAlert :error="addError" :known-fields="['email', 'role']" />
          <div class="d-flex flex-wrap ga-4">
            <v-text-field
              v-model="email"
              name="member_email"
              type="email"
              :label="t('members.add.email')"
              :error-messages="addFields.email"
              class="flex-grow-1"
            />
            <v-select
              v-model="role"
              data-test="add-role"
              :items="roleItems"
              :label="t('members.add.role')"
              :error-messages="addFields.role"
              min-width="200"
            />
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn type="submit" color="primary" variant="flat" :loading="adding">
            {{ t('members.add.submit') }}
          </v-btn>
        </v-card-actions>
      </v-form>
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
</template>
