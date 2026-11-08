<script setup lang="ts">
import type { Beat } from '../../stores/monitors'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  beats: Beat[] | undefined
  /** Fixed number of slots; the newest beat is always at the right edge, empty slots fill the left. */
  slots?: number
  size?: 'small' | 'large'
}>(), {
  slots: 40,
  size: 'small',
})

const { t } = useI18n()

const shown = computed(() => (props.beats ?? []).slice(-props.slots))

const label = computed(() => {
  const beats = shown.value
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

const empty = computed(() => Math.max(0, props.slots - shown.value.length))
</script>

<template>
  <span class="heartbeat" :class="`heartbeat--${size}`" role="img" :aria-label="label" :title="label">
    <span v-for="i in empty" :key="`e${i}`" class="beat beat--empty" />
    <span
      v-for="(beat, i) in shown"
      :key="`${i}-${beat.at}`"
      class="beat"
      :class="`beat--${beat.status}`"
    />
  </span>
</template>

<style scoped>
.heartbeat {
  display: flex;
  align-items: stretch;
  width: 100%;
  min-width: 0;
}

.heartbeat--small {
  gap: 2px;
  height: 16px;
}

.heartbeat--large {
  gap: 3px;
  height: 44px;
}

.beat {
  flex: 1 1 0;
  min-width: 2px;
  border-radius: 2px;
  background: var(--iw-empty);
}

.heartbeat--large .beat {
  border-radius: 3px;
}

.beat--up {
  background: var(--iw-up-fill);
}

/* Hatched as well as red, so an outage reads without colour. */
.beat--down {
  background: repeating-linear-gradient(135deg, var(--iw-down-fill) 0 2px, var(--iw-down-hatch) 2px 4px);
}

.heartbeat--large .beat--down {
  background: repeating-linear-gradient(135deg, var(--iw-down-fill) 0 3px, var(--iw-down-hatch) 3px 6px);
}

.beat--degraded {
  background: var(--iw-degraded-fill);
}

.beat--maintenance {
  background: var(--iw-maintenance-fill);
}

.beat--pending,
.beat--paused {
  background: var(--iw-idle-fill);
}
</style>
