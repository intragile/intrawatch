<script setup lang="ts">
import type { ApiError } from '../../api'
import type { AuditRow } from './rows'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, useCursorList } from '../../api'
import DateTime from '../../components/common/DateTime.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import HelpLink from '../../components/help/HelpLink.vue'
import { screenHelp } from '../../components/help/manual'
import { useCan } from '../../composables/useCan'
import { bodyOf } from '../../utils/cursorPage'
import { formatChange, parseAuditEvent } from './rows'

type ActorType = 'user' | 'token' | 'system'

const ACTOR_TYPES: ActorType[] = ['user', 'token', 'system']

const { t } = useI18n()
const route = useRoute()
const can = useCan()

const slug = computed(() => String(route.params.workspace))

const action = ref('')
const actorType = ref<ActorType | null>(null)
const actorUserId = ref('')
const actorId = ref('')

/** Filters as last applied; typing in the form changes nothing until it is submitted. */
const applied = ref<{ action?: string, actor_type?: ActorType, actor_user_id?: number, actor_id?: number }>({})

const list = useCursorList(async (cursor) => {
  const result = await bodyOf(api.GET('/workspaces/{workspace}/audit', {
    params: {
      path: { workspace: slug.value },
      query: { ...applied.value, cursor: cursor ?? undefined },
    },
  }))
  return {
    data: result.data.map(parseAuditEvent),
    meta: result.meta,
  }
})

const actorTypeItems = computed(() => ACTOR_TYPES.map(type => ({
  value: type,
  title: t(`audit.actorTypes.${type}`),
})))

function positive(value: string): number | undefined {
  const n = Number.parseInt(value, 10)
  return Number.isInteger(n) && n > 0
    ? n
    : undefined
}

function apply(): void {
  applied.value = {
    ...(action.value.trim() === ''
      ? {}
      : {
          action: action.value.trim(),
        }),
    ...(actorType.value === null
      ? {}
      : { actor_type: actorType.value }),
    ...(positive(actorUserId.value) === undefined
      ? {}
      : {
          actor_user_id: positive(actorUserId.value),
        }),
    ...(positive(actorId.value) === undefined
      ? {}
      : {
          actor_id: positive(actorId.value),
        }),
  }
  void list.reload()
}

function clear(): void {
  action.value = ''
  actorType.value = null
  actorUserId.value = ''
  actorId.value = ''
  apply()
}

function actorLabel(row: AuditRow): string {
  if (row.actorType === 'user')
    return t('audit.actorUserLabel', { id: row.actorUserId ?? row.actorId ?? '?' })
  if (row.actorType === 'token') {
    return t('audit.actorTokenLabel', {
      id: row.actorId ?? '?',
      owner: row.actorUserId ?? '?',
    })
  }
  return t('audit.actorSystemLabel')
}

const loadError = computed<ApiError | null>(() => list.error.value)

watch(slug, clear)
if (can('viewAudit'))
  void list.loadMore()
</script>

<template>
  <div class="d-flex align-center ga-2 mb-4">
    <h1 class="text-h5">
      {{ t('audit.title') }}
    </h1>
    <HelpLink v-bind="screenHelp('ws.audit')" />
  </div>

  <EmptyState v-if="!can('viewAudit')" :title="t('common.noAccess')" />

  <template v-else>
    <v-card class="mb-4">
      <v-card-title tag="h2" class="text-h6">
        {{ t('audit.filters') }}
      </v-card-title>
      <v-form @submit.prevent="apply">
        <v-card-text class="d-flex flex-wrap ga-4">
          <v-text-field
            v-model="action"
            name="audit_action"
            :label="t('audit.action')"
            maxlength="64"
            density="compact"
            hide-details
          />
          <v-select
            v-model="actorType"
            data-test="filter-actor-type"
            :items="actorTypeItems"
            :label="t('audit.actorType')"
            clearable
            density="compact"
            hide-details
            min-width="200"
          />
          <v-text-field
            v-model="actorUserId"
            name="audit_actor_user_id"
            type="number"
            min="1"
            :label="t('audit.actorUser')"
            density="compact"
            hide-details
          />
          <v-text-field
            v-model="actorId"
            name="audit_actor_id"
            type="number"
            min="1"
            :label="t('audit.actorId')"
            density="compact"
            hide-details
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="clear">
            {{ t('common.clear') }}
          </v-btn>
          <v-btn type="submit" color="primary" variant="flat">
            {{ t('audit.apply') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>

    <ProblemAlert :error="loadError" />

    <v-table v-if="list.items.value.length > 0">
      <caption class="d-sr-only">
        {{ t('audit.list') }}
      </caption>
      <thead>
        <tr>
          <th scope="col">
            {{ t('audit.time') }}
          </th>
          <th scope="col">
            {{ t('audit.action') }}
          </th>
          <th scope="col">
            {{ t('audit.actor') }}
          </th>
          <th scope="col">
            {{ t('audit.subject') }}
          </th>
          <th scope="col">
            {{ t('audit.changes') }}
          </th>
          <th scope="col">
            {{ t('audit.ip') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list.items.value" :key="row.id">
          <td class="text-no-wrap">
            <DateTime :value="row.createdAt" />
          </td>
          <td><code>{{ row.action }}</code></td>
          <td>{{ actorLabel(row) }}</td>
          <td>
            {{ row.subjectType ?? '' }}<template v-if="row.subjectId !== null">
              #{{ row.subjectId }}
            </template>
          </td>
          <td>
            <ul class="changes">
              <li v-for="change in row.changes" :key="change.field">
                <strong>{{ change.field }}</strong>:
                <template v-if="change.from !== undefined">
                  {{ formatChange(change.from) }} &rarr;
                </template>
                {{ formatChange(change.to) }}
              </li>
            </ul>
          </td>
          <td>{{ row.ip ?? '' }}</td>
        </tr>
      </tbody>
    </v-table>

    <EmptyState v-else-if="!list.loading.value && !list.error.value" :title="t('audit.empty')" />

    <div v-if="list.hasMore.value && list.items.value.length > 0" class="mt-4">
      <v-btn data-test="load-more" variant="tonal" :loading="list.loading.value" @click="list.loadMore()">
        {{ t('common.loadMore') }}
      </v-btn>
    </div>
  </template>
</template>

<style scoped>
.changes {
  list-style: none;
  padding: 0;
  margin: 0;
  overflow-wrap: anywhere;
}
</style>
