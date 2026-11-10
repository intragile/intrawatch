<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import { useWorkspaceLive } from '../../live/useWorkspaceLive'

const { t } = useI18n()
const live = useWorkspaceLive()
// The mobile top bar has room for a word, not a sentence: the full text stays in the tooltip and label.
const { smAndDown: compact } = useDisplay()

// The dot's colour is paired with its shape (filled, ring, hollow) and the text.
const look = computed(() => {
  switch (live.state) {
    case 'live':
      return {
        cls: 'live',
        text: t('live.live'),
      }
    case 'connecting':
      return {
        cls: 'connecting',
        text: t('live.connecting'),
      }
    default:
      return {
        cls: 'offline',
        text: t('live.offline'),
        short: t('live.offlineShort'),
      }
  }
})
</script>

<template>
  <span
    class="live-indicator"
    :class="look.cls"
    :data-state="live.state"
    role="status"
    :aria-label="`${t('live.label')}: ${look.text}`"
    :title="look.text"
    data-test="live-indicator"
  >
    <span class="dot" aria-hidden="true" />
    <span class="text">{{ compact && 'short' in look ? look.short : look.text }}</span>
  </span>
</template>

<style scoped>
.live-indicator {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dot {
  flex: none;
  width: 9px;
  height: 9px;
  border-radius: 50%;
}

.live .dot {
  background: var(--iw-up-fill);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--iw-up-fill) 25%, transparent);
}

.connecting .dot {
  border: 2px solid var(--iw-degraded-fill);
}

.offline .dot {
  border: 2px solid var(--iw-idle-fill);
}
</style>
