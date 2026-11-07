<script setup lang="ts">
import type { IncidentImpact } from '../../api/types'
import { mdiAlertOctagon, mdiAlertOutline, mdiInformationOutline, mdiMinusCircleOutline } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  impact: IncidentImpact
  size?: 'x-small' | 'small' | 'default'
}>(), {
  size: 'small',
})

// The label and icon carry the impact; colour only reinforces it.
const look: Record<IncidentImpact, { color: string | undefined, icon: string }> = {
  none: { color: undefined, icon: mdiMinusCircleOutline },
  minor: { color: 'info', icon: mdiInformationOutline },
  major: { color: 'warning', icon: mdiAlertOutline },
  critical: { color: 'error', icon: mdiAlertOctagon },
}

const { t } = useI18n()
const current = computed(() => look[props.impact])
</script>

<template>
  <v-chip :color="current.color" :prepend-icon="current.icon" :size="size" variant="outlined" label>
    {{ t(`incidents.impact.${impact}`) }}
  </v-chip>
</template>
