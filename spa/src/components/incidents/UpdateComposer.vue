<script setup lang="ts">
import type { ApiError } from '../../api'
import type { IncidentStatus } from '../../api/types'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '../../api'
import ProblemAlert from '../common/ProblemAlert.vue'
import { INCIDENT_STATUSES } from './model'

const props = defineProps<{
  /** The incident's current status; the composer starts from it. */
  current: IncidentStatus
  busy: boolean
  error: ApiError | null
}>()

const emit = defineEmits<{ post: [update: { status: IncidentStatus, message: string }] }>()

const { t } = useI18n()
const status = ref<IncidentStatus>(props.current)
const message = ref('')

// A posted update changes the incident's status; the next one starts from it.
watch(() => props.current, (next) => {
  status.value = next
})

const items = computed(() => INCIDENT_STATUSES.map(value => ({
  value,
  title: t(`incidents.status.${value}`),
})))
const fields = computed(() => fieldErrors(props.error))
const known = ['status', 'message']

/** Clears the message after a successful post; the status stays on what was just set. */
function reset(): void {
  message.value = ''
}
defineExpose({ reset })

function post(): void {
  if (props.busy || message.value.trim() === '')
    return
  emit('post', { status: status.value, message: message.value })
}
</script>

<template>
  <v-form @submit.prevent="post">
    <ProblemAlert :error="error" :known-fields="known" />
    <v-select
      v-model="status"
      data-test="update-status"
      name="update_status"
      :items="items"
      :label="t('incidents.fields.status')"
      :error-messages="fields.status"
    />
    <v-textarea
      v-model="message"
      name="update_message"
      :label="`${t('incidents.fields.message')} *`"
      :hint="t('incidents.composer.hint')"
      persistent-hint
      rows="3"
      maxlength="10000"
      :error-messages="fields.message"
    />
    <div class="d-flex justify-end mt-2">
      <v-btn
        type="submit"
        color="primary"
        variant="flat"
        data-test="update-post"
        :loading="busy"
        :disabled="message.trim() === ''"
      >
        {{ t('incidents.composer.submit') }}
      </v-btn>
    </div>
  </v-form>
</template>
