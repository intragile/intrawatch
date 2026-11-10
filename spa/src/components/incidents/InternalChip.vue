<script setup lang="ts">
import { mdiLockOutline } from '@mdi/js'
import { useI18n } from 'vue-i18n'

withDefaults(defineProps<{
  size?: 'x-small' | 'small' | 'default'
}>(), {
  size: 'small',
})

const { t } = useI18n()
</script>

<template>
  <v-chip data-test="incident-internal" :prepend-icon="mdiLockOutline" :size="size" variant="outlined" label>
    {{ t('incidents.internal.chip') }}
  </v-chip>
</template>
