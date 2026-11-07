<script setup lang="ts">
import type { IncidentStatus } from '../../api/types'
import { mdiCheckCircle, mdiEyeOutline, mdiMagnify, mdiTargetVariant } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  status: IncidentStatus
  size?: 'x-small' | 'small' | 'default'
}>(), {
  size: 'small',
})

// The label and icon carry the status; colour only reinforces it.
const look: Record<IncidentStatus, { color: string, icon: string }> = {
  investigating: { color: 'error', icon: mdiMagnify },
  identified: { color: 'warning', icon: mdiTargetVariant },
  monitoring: { color: 'info', icon: mdiEyeOutline },
  resolved: { color: 'success', icon: mdiCheckCircle },
}

const { t } = useI18n()
const current = computed(() => look[props.status])
</script>

<template>
  <v-chip :color="current.color" :prepend-icon="current.icon" :size="size" label>
    {{ t(`incidents.status.${status}`) }}
  </v-chip>
</template>
