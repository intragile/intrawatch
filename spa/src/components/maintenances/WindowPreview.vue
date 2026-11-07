<script setup lang="ts">
import type { Maintenance } from '../../api/types'
import { mdiCalendarClock, mdiWrench } from '@mdi/js'
import { useI18n } from 'vue-i18n'
import DateTime from '../common/DateTime.vue'

defineProps<{
  /** The window the API reports: the one in progress, or else the next. */
  window: Maintenance['next_window']
  inProgress: boolean
  /** IANA zone the times are shown in. */
  timezone: string
}>()

const { t } = useI18n()
</script>

<template>
  <div data-test="window-preview">
    <p v-if="window" class="d-flex align-center ga-2 flex-wrap">
      <v-icon :icon="inProgress ? mdiWrench : mdiCalendarClock" size="small" />
      <span class="font-weight-medium">
        {{ inProgress ? t('maintenances.preview.current') : t('maintenances.preview.next') }}:
      </span>
      <span>
        <DateTime :value="window.starts_at" :timezone="timezone" />
        <template v-if="window.ends_at">
          –
          <DateTime :value="window.ends_at" :timezone="timezone" />
        </template>
      </span>
    </p>
    <p v-else class="text-medium-emphasis">
      {{ t('maintenances.preview.none') }}
    </p>
    <p class="text-caption text-medium-emphasis">
      {{ t('maintenances.preview.saved') }}
    </p>
  </div>
</template>
