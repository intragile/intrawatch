<script setup lang="ts">
import type { MonitorStatus } from '../../api/types'
import { useI18n } from 'vue-i18n'
import StatusMark from './StatusMark.vue'

withDefaults(defineProps<{
  status: MonitorStatus
  size?: 'x-small' | 'small' | 'default'
}>(), {
  size: 'small',
})

// The word carries the status; the mark's colour and shape back it up.
const TEXT: Record<MonitorStatus, string> = {
  up: 'text-success',
  down: 'text-error',
  degraded: 'text-warning',
  maintenance: 'text-maintenance',
  pending: 'text-idle',
  paused: 'text-idle',
}

const MARK: Record<'x-small' | 'small' | 'default', number> = {
  'x-small': 9,
  'small': 11,
  'default': 13,
}

const { t } = useI18n()
</script>

<template>
  <span class="status-chip" :class="[`status-chip--${size}`, TEXT[status]]" :data-status="status">
    <StatusMark :status="status" :size="MARK[size]" />
    <span>{{ t(`status.${status}`) }}</span>
  </span>
</template>

<style scoped>
.status-chip {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-weight: 600;
  white-space: nowrap;
  line-height: 1.2;
}

.status-chip--x-small {
  gap: 6px;
  font-size: 0.8125rem;
}

.status-chip--small {
  font-size: 0.875rem;
}

.status-chip--default {
  font-size: 0.9375rem;
}
</style>
