<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formError } from '../../api'

const props = withDefaults(defineProps<{
  error: ApiError | null
  /** Fields rendered inline by the form; their messages are not repeated here. */
  knownFields?: string[]
}>(), {
  knownFields: () => [],
})

const { t } = useI18n()

const title = computed(() => {
  if (!props.error)
    return ''
  if (props.error.status === 0)
    return t('problem.network')
  return props.error.title || t('problem.generic')
})

const text = computed(() => (props.error
  ? formError(props.error, props.knownFields)
  : null))

// A 422 whose every message sits on a field needs no extra alert.
const visible = computed(() => props.error !== null && !(props.error.status === 422 && text.value === null))
</script>

<template>
  <v-alert
    v-if="visible"
    type="error"
    variant="tonal"
    role="alert"
    class="mb-4 problem-alert"
    :title="title"
    :text="text ?? undefined"
  />
</template>

<style scoped>
.problem-alert :deep(.v-alert__content) {
  white-space: pre-line;
}
</style>
