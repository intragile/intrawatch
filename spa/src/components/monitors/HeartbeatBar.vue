<script setup lang="ts">
import type { Beat } from '../../stores/monitors'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  beats: Beat[] | undefined
}>()

const { t } = useI18n()

const label = computed(() => {
  const beats = props.beats ?? []
  if (beats.length === 0)
    return t('monitors.beats.none')
  const up = beats.filter(b => b.status === 'up').length
  const down = beats.filter(b => b.status === 'down').length
  return t('monitors.beats.label', {
    count: beats.length,
    up,
    down,
    other: beats.length - up - down,
  })
})

// Colour is backed by bar height and the text label of the whole bar.
function look(status: Beat['status']): { cls: string, height: number } {
  switch (status) {
    case 'up':
      return { cls: 'bg-success', height: 16 }
    case 'down':
      return { cls: 'bg-error', height: 8 }
    case 'degraded':
      return { cls: 'bg-warning', height: 12 }
    default:
      return { cls: 'bg-grey', height: 12 }
  }
}
</script>

<template>
  <span class="heartbeat" role="img" :aria-label="label" :title="label">
    <template v-if="beats && beats.length > 0">
      <span
        v-for="(beat, i) in beats"
        :key="`${i}-${beat.at}`"
        class="beat"
        :class="look(beat.status).cls"
        :style="{ height: `${look(beat.status).height}px` }"
      />
    </template>
    <span v-else class="text-medium-emphasis" aria-hidden="true">—</span>
  </span>
</template>

<style scoped>
.heartbeat {
  display: inline-flex;
  align-items: flex-end;
  gap: 1px;
  height: 16px;
}

.beat {
  display: inline-block;
  width: 3px;
  border-radius: 1px;
}
</style>
