<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSession } from '../../stores/session'

const props = defineProps<{
  /** ISO 8601 timestamp. */
  value: string | null | undefined
  /** IANA zone; defaults to the current workspace's timezone. */
  timezone?: string
}>()

const session = useSession()
const { locale } = useI18n()

const zone = computed(() => props.timezone ?? session.current?.timezone ?? 'UTC')

const text = computed(() => {
  if (!props.value)
    return ''
  const date = new Date(props.value)
  if (Number.isNaN(date.getTime()))
    return props.value
  try {
    return new Intl.DateTimeFormat(locale.value, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      timeZone: zone.value,
      timeZoneName: 'short',
    }).format(date)
  }
  catch {
    return date.toISOString()
  }
})
</script>

<template>
  <time v-if="value" :datetime="value" :title="zone">{{ text }}</time>
  <span v-else aria-hidden="true">—</span>
</template>
