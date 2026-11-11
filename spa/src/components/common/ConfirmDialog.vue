<script setup lang="ts">
import { useI18n } from 'vue-i18n'

withDefaults(defineProps<{
  title: string
  text?: string
  confirmLabel?: string
  /** Styles the confirm button as a destructive action. */
  destructive?: boolean
  loading?: boolean
  /** Keeps the confirm button inactive, e.g. until a typed confirmation matches. */
  confirmDisabled?: boolean
}>(), {
  text: undefined,
  confirmLabel: undefined,
  destructive: false,
  loading: false,
  confirmDisabled: false,
})

const emit = defineEmits<{ confirm: [] }>()
const open = defineModel<boolean>({ default: false })
const { t } = useI18n()
</script>

<template>
  <v-dialog v-model="open" max-width="440">
    <v-card :title="title">
      <v-card-text v-if="text || $slots.default">
        <slot>{{ text }}</slot>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn data-test="cancel" variant="text" :disabled="loading" @click="open = false">
          {{ t('common.cancel') }}
        </v-btn>
        <v-btn
          data-test="confirm"
          :color="destructive ? 'error' : 'primary'"
          variant="flat"
          :loading="loading"
          :disabled="confirmDisabled"
          @click="emit('confirm')"
        >
          {{ confirmLabel ?? t('common.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
