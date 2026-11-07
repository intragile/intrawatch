<script setup lang="ts">
import type { Schemas } from '../../api/types'
import { mdiAlertCircle, mdiCheckCircle, mdiClockOutline } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  status: Schemas['DeliveryStatus']
}>()

// Colour is paired with an icon and the word, so the state never relies on colour alone.
const look: Record<Schemas['DeliveryStatus'], { color: string, icon: string }> = {
  queued: { color: 'grey', icon: mdiClockOutline },
  sent: { color: 'success', icon: mdiCheckCircle },
  failed: { color: 'error', icon: mdiAlertCircle },
}

const { t } = useI18n()
const current = computed(() => look[props.status])
</script>

<template>
  <v-chip :color="current.color" :prepend-icon="current.icon" size="x-small" label>
    {{ t(`channels.delivery.${status}`) }}
  </v-chip>
</template>
