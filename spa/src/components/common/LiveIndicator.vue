<script setup lang="ts">
import { mdiCircle, mdiCircleOutline, mdiProgressClock } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceLive } from '../../live/useWorkspaceLive'

const { t } = useI18n()
const live = useWorkspaceLive()

// The dot colour is always paired with an icon shape and the text.
const look = computed(() => {
  switch (live.state) {
    case 'live':
      return {
        color: 'success',
        icon: mdiCircle,
        text: t('live.live'),
      }
    case 'connecting':
      return {
        color: 'warning',
        icon: mdiProgressClock,
        text: t('live.connecting'),
      }
    default:
      return {
        color: 'grey',
        icon: mdiCircleOutline,
        text: t('live.offline'),
      }
  }
})
</script>

<template>
  <v-chip
    :color="look.color"
    :prepend-icon="look.icon"
    :data-state="live.state"
    size="small"
    variant="tonal"
    role="status"
    :aria-label="`${t('live.label')}: ${look.text}`"
    data-test="live-indicator"
  >
    {{ look.text }}
  </v-chip>
</template>
