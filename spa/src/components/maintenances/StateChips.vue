<script setup lang="ts">
import type { Maintenance } from '../../api/types'
import { mdiCalendarClock, mdiCheckCircleOutline, mdiPauseCircleOutline, mdiWrench } from '@mdi/js'
import { useI18n } from 'vue-i18n'

defineProps<{
  maintenance: Pick<Maintenance, 'in_progress' | 'next_window' | 'active'>
}>()

const { t } = useI18n()
</script>

<template>
  <!-- The state comes from the API's own computation of the schedule; the text and icon carry it, not the colour. -->
  <v-chip v-if="maintenance.in_progress" size="small" label color="info" :prepend-icon="mdiWrench">
    {{ t('maintenances.state.inProgress') }}
  </v-chip>
  <v-chip v-else-if="maintenance.next_window" size="small" label :prepend-icon="mdiCalendarClock">
    {{ t('maintenances.state.upcoming') }}
  </v-chip>
  <v-chip v-else size="small" label :prepend-icon="mdiCheckCircleOutline">
    {{ t('maintenances.state.ended') }}
  </v-chip>
  <v-chip v-if="!maintenance.active" size="small" label class="ml-1" :prepend-icon="mdiPauseCircleOutline">
    {{ t('maintenances.state.inactive') }}
  </v-chip>
</template>
