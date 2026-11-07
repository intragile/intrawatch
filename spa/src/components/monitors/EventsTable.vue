<script setup lang="ts">
import type { CursorList } from '../../api'
import type { MonitorEvent } from '../../api/types'
import { useI18n } from 'vue-i18n'
import DateTime from '../common/DateTime.vue'
import EmptyState from '../common/EmptyState.vue'
import ProblemAlert from '../common/ProblemAlert.vue'
import StatusChip from '../common/StatusChip.vue'

defineProps<{ list: CursorList<MonitorEvent> }>()

const { t } = useI18n()
</script>

<template>
  <ProblemAlert :error="list.error.value" />
  <v-table v-if="list.items.value.length > 0" density="compact">
    <caption class="d-sr-only">
      {{ t('monitorDetail.events.title') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('monitorDetail.time') }}
        </th>
        <th scope="col">
          {{ t('monitorDetail.events.change') }}
        </th>
        <th scope="col">
          {{ t('monitorDetail.message') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in list.items.value" :key="row.id">
        <td><DateTime :value="row.created_at" /></td>
        <td>
          <span v-if="row.kind === 'status_changed' && row.to_status" class="d-inline-flex align-center ga-1">
            <StatusChip v-if="row.from_status" :status="row.from_status" size="x-small" />
            <span aria-hidden="true">→</span>
            <span class="d-sr-only">{{ t('monitorDetail.events.to') }}</span>
            <StatusChip :status="row.to_status" size="x-small" />
          </span>
          <span v-else>{{ row.key }}: {{ row.old_value ?? '—' }} → {{ row.new_value ?? '—' }}</span>
        </td>
        <td>{{ row.message }}</td>
      </tr>
    </tbody>
  </v-table>
  <EmptyState v-else-if="!list.loading.value && list.error.value === null" :title="t('monitorDetail.events.empty')" />
  <div class="d-flex justify-center mt-2">
    <v-btn v-if="list.hasMore.value && list.items.value.length > 0" :loading="list.loading.value" variant="text" @click="list.loadMore()">
      {{ t('common.loadMore') }}
    </v-btn>
  </div>
</template>
