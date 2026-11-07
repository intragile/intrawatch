<script setup lang="ts">
import type { IncidentTimelineEntry } from '../../api/types'
import { mdiPencil, mdiTrashCanOutline } from '@mdi/js'
import { useI18n } from 'vue-i18n'
import DateTime from '../common/DateTime.vue'
import IncidentStatusChip from './IncidentStatusChip.vue'

defineProps<{
  entries: IncidentTimelineEntry[]
  /** Whether edit and delete are offered. */
  editable: boolean
}>()

const emit = defineEmits<{
  edit: [entry: IncidentTimelineEntry]
  remove: [entry: IncidentTimelineEntry]
}>()

const { t } = useI18n()
</script>

<template>
  <p v-if="entries.length === 0" class="text-medium-emphasis">
    {{ t('incidents.timeline.empty') }}
  </p>
  <ol v-else class="timeline pa-0">
    <li v-for="entry in entries" :key="entry.id" class="timeline-entry mb-4 pl-4" :data-test="`update-${entry.id}`">
      <div class="d-flex align-center flex-wrap ga-2">
        <IncidentStatusChip :status="entry.status" size="small" />
        <span class="text-caption text-medium-emphasis">
          <DateTime :value="entry.created_at" />
          <template v-if="entry.updated_at">
            ({{ t('incidents.timeline.edited') }})
          </template>
        </span>
        <v-spacer />
        <template v-if="editable">
          <v-btn
            icon
            variant="text"
            size="small"
            :data-test="`update-edit-${entry.id}`"
            :aria-label="t('incidents.timeline.edit')"
            @click="emit('edit', entry)"
          >
            <v-icon :icon="mdiPencil" />
          </v-btn>
          <v-btn
            icon
            variant="text"
            size="small"
            color="error"
            :data-test="`update-delete-${entry.id}`"
            :aria-label="t('incidents.timeline.delete')"
            @click="emit('remove', entry)"
          >
            <v-icon :icon="mdiTrashCanOutline" />
          </v-btn>
        </template>
      </div>
      <p class="mt-1 message">
        {{ entry.message }}
      </p>
    </li>
  </ol>
</template>

<style scoped>
.timeline {
  list-style: none;
}
.timeline-entry {
  border-left: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.message {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>
