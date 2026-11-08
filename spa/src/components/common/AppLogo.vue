<script setup lang="ts">
import { useI18n } from 'vue-i18n'

withDefaults(defineProps<{
  /** Mark size in px; the wordmark scales with it. */
  size?: number
  /** Mark only, for tight spaces. */
  markOnly?: boolean
}>(), {
  size: 26,
  markOnly: false,
})

const { t } = useI18n()
</script>

<template>
  <span class="app-logo" :style="{ '--logo-size': `${size}px` }">
    <svg
      class="app-logo__mark"
      :width="size"
      :height="size"
      viewBox="0 0 26 26"
      :role="markOnly ? 'img' : undefined"
      :aria-label="markOnly ? t('app.name') : undefined"
      :aria-hidden="markOnly ? undefined : 'true'"
    >
      <rect x="1" y="1" width="24" height="24" rx="6" class="app-logo__tile" />
      <path d="M5 14 H9 L11 9 L14 18 L16 12 L17 14 H21" class="app-logo__pulse" />
    </svg>
    <span v-if="!markOnly" class="app-logo__word iw-display">{{ t('app.name') }}</span>
  </span>
</template>

<style scoped>
.app-logo {
  display: inline-flex;
  align-items: center;
  gap: calc(var(--logo-size) * 0.4);
  color: rgb(var(--v-theme-on-surface));
}

.app-logo__mark {
  flex: none;
}

.app-logo__tile {
  fill: #2346b5;
}

.app-logo__pulse {
  fill: none;
  stroke: #fff;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.app-logo__word {
  font-size: calc(var(--logo-size) * 0.78);
  line-height: 1;
  letter-spacing: 0.01em;
}
</style>
