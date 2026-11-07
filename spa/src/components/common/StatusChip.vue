<script setup lang="ts">
import type { MonitorStatus } from '../../api/types'
import { mdiAlertCircle, mdiCheckCircle, mdiClockOutline, mdiCloseCircle, mdiPauseCircle, mdiWrench } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  status: MonitorStatus
  size?: 'x-small' | 'small' | 'default'
}>(), {
  size: 'small',
})

// Colour is paired with an icon and the text, so the status never relies on colour alone.
const look: Record<MonitorStatus, { color: string, icon: string }> = {
  up: { color: 'success', icon: mdiCheckCircle },
  down: { color: 'error', icon: mdiCloseCircle },
  degraded: { color: 'warning', icon: mdiAlertCircle },
  maintenance: { color: 'info', icon: mdiWrench },
  pending: { color: 'grey', icon: mdiClockOutline },
  paused: { color: 'grey', icon: mdiPauseCircle },
}

const { t } = useI18n()
const current = computed(() => look[props.status])
</script>

<template>
  <v-chip :color="current.color" :prepend-icon="current.icon" :size="size" label>
    {{ t(`status.${status}`) }}
  </v-chip>
</template>
