<script setup lang="ts">
import type { Beat } from '../../stores/monitors'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  beats: Beat[] | undefined
  /** Fixed number of slots; the newest beat is always at the right edge, empty slots fill the left. */
  slots?: number
  size?: 'small' | 'large'
  /** A group's beats are its evaluations, derived from its members; with none yet it says so instead of showing empty slots. */
  group?: boolean
  /** The beats are still loading: an empty group keeps its frame but claims nothing yet. */
  pending?: boolean
}>(), {
  slots: 40,
  size: 'small',
  group: false,
  pending: false,
})

const { t } = useI18n()

const shown = computed(() => (props.beats ?? []).slice(-props.slots))

const label = computed(() => {
  const beats = shown.value
  if (beats.length === 0)
    return t(props.group ? 'monitors.beats.groupNone' : 'monitors.beats.none')
  const up = beats.filter(b => b.status === 'up').length
  const down = beats.filter(b => b.status === 'down').length
  return t(props.group ? 'monitors.beats.groupLabel' : 'monitors.beats.label', {
    count: beats.length,
    up,
    down,
    other: beats.length - up - down,
  })
})

const empty = computed(() => Math.max(0, props.slots - shown.value.length))
const placeholder = computed(() => props.group && shown.value.length === 0)
</script>

<template>
  <span v-if="placeholder" class="heartbeat heartbeat--placeholder" :class="`heartbeat--${size}`" role="img" :aria-label="pending ? undefined : label" :title="pending ? undefined : label" :aria-busy="pending || undefined" data-test="heartbeat-placeholder">
    <span v-if="!pending" class="placeholder-text">{{ label }}</span>
  </span>
  <span v-else class="heartbeat" :class="`heartbeat--${size}`" role="img" :aria-label="label" :title="label">
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

/* An unevaluated group: the strip's footprint, outlined, with the reason in it. */
.heartbeat--placeholder {
  align-items: center;
  justify-content: center;
  padding: 0 8px;
  border: 1px dashed var(--iw-line);
  border-radius: 3px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.6875rem;
  line-height: 1.2;
}

.placeholder-text {
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.heartbeat--placeholder.heartbeat--large {
  font-size: 0.875rem;
  border-radius: 4px;
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
