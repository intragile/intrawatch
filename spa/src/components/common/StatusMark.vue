<script setup lang="ts">
import type { MonitorStatus } from '../../api/types'

withDefaults(defineProps<{
  status: MonitorStatus
  /** Edge length in px. */
  size?: number
}>(), {
  size: 12,
})
</script>

<template>
  <!-- Decorative: the status word always sits next to it, or the parent carries the label. -->
  <span
    class="status-mark"
    :class="`status-mark--${status}`"
    :style="{ width: `${size}px`, height: `${size}px` }"
    aria-hidden="true"
  />
</template>

<style scoped>
.status-mark {
  display: inline-block;
  flex: none;
  border-radius: 3px;
}

.status-mark--up {
  background: var(--iw-up-fill);
}

/* Hatched as well as red, so a down monitor reads without colour. */
.status-mark--down {
  background: repeating-linear-gradient(135deg, var(--iw-down-fill) 0 2px, var(--iw-down-hatch) 2px 4px);
  box-shadow: 0 0 0 1px var(--iw-down-fill);
}

.status-mark--degraded {
  background: var(--iw-degraded-fill);
}

.status-mark--maintenance {
  background: var(--iw-maintenance-fill);
}

/* Hollow: nothing is being measured. */
.status-mark--paused,
.status-mark--pending {
  box-shadow: inset 0 0 0 2px var(--iw-idle-fill);
}
</style>
