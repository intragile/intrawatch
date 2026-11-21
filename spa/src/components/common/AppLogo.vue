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
      viewBox="0 0 24 24"
      :role="markOnly ? 'img' : undefined"
      :aria-label="markOnly ? t('app.name') : undefined"
      :aria-hidden="markOnly ? undefined : 'true'"
    >
      <!-- The intrawatch.eu mark: blue tile, white bars, the third bar (the signal) coral. -->
      <rect width="24" height="24" rx="5.5" class="app-logo__tile" />
      <rect x="3" y="7" width="3" height="10" rx="1" class="app-logo__bar" />
      <rect x="8" y="7" width="3" height="10" rx="1" class="app-logo__bar" />
      <rect x="13" y="7" width="3" height="10" rx="1" class="app-logo__signal" />
      <rect x="18" y="7" width="3" height="10" rx="1" class="app-logo__bar" />
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

.app-logo__bar {
  fill: #fff;
}

.app-logo__signal {
  fill: #e2816e;
}

.app-logo__word {
  font-size: calc(var(--logo-size) * 0.78);
  line-height: 1;
  letter-spacing: 0.01em;
}
</style>
