<script setup lang="ts">
import type { ChannelSummary } from '../../../api/types'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  channels: ChannelSummary[]
  loading?: boolean
  error?: string
}>()

const model = defineModel<number[]>({
  default: () => [],
})
const { t } = useI18n()

const items = computed(() => props.channels.map(c => ({
  value: c.id,
  title: c.active
    ? c.name
    : `${c.name} (${t('monitorEditor.notifications.inactive')})`,
})))
</script>

<template>
  <v-select
    v-model="model"
    name="notification_channel_ids"
    :items="items"
    multiple
    chips
    closable-chips
    :loading="loading"
    :label="t('monitorEditor.notifications.label')"
    :hint="channels.length === 0 && !loading ? t('monitorEditor.notifications.none') : t('monitorEditor.notifications.hint')"
    persistent-hint
    :error-messages="error"
  />
</template>
