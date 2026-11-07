<script setup lang="ts">
import type { CursorList } from '../../api'
import type { CheckResult } from '../../api/types'
import { useI18n } from 'vue-i18n'
import DateTime from '../common/DateTime.vue'
import EmptyState from '../common/EmptyState.vue'
import ProblemAlert from '../common/ProblemAlert.vue'
import StatusChip from '../common/StatusChip.vue'

defineProps<{ list: CursorList<CheckResult> }>()

const { t } = useI18n()
</script>

<template>
  <ProblemAlert :error="list.error.value" />
  <v-table v-if="list.items.value.length > 0" density="compact">
    <caption class="d-sr-only">
      {{ t('monitorDetail.results.title') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('monitorDetail.time') }}
        </th>
        <th scope="col">
          {{ t('monitorDetail.status') }}
        </th>
        <th scope="col">
          {{ t('monitorDetail.results.latency') }}
        </th>
        <th scope="col">
          {{ t('monitorDetail.message') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in list.items.value" :key="row.id">
        <td><DateTime :value="row.checked_at" /></td>
        <td><StatusChip :status="row.status" size="x-small" /></td>
        <td>{{ row.latency_ms === null ? '—' : t('monitors.latencyMs', { ms: row.latency_ms }) }}</td>
        <td>{{ row.message ?? '—' }}</td>
      </tr>
    </tbody>
  </v-table>
  <EmptyState v-else-if="!list.loading.value && list.error.value === null" :title="t('monitorDetail.results.empty')" />
  <div class="d-flex justify-center mt-2">
    <v-btn v-if="list.hasMore.value && list.items.value.length > 0" :loading="list.loading.value" variant="text" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>
</template>
