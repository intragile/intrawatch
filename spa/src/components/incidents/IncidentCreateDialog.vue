<script setup lang="ts">
import type { ApiError } from '../../api'
import type { IncidentCreateBody, IncidentImpact, IncidentStatus } from '../../api/types'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '../../api'
import { useCan } from '../../composables/useCan'
import ProblemAlert from '../common/ProblemAlert.vue'
import AffectedPicker from './AffectedPicker.vue'
import { INCIDENT_IMPACTS, INCIDENT_STATUSES } from './model'

const props = defineProps<{
  slug: string
  busy: boolean
  error: ApiError | null
}>()

const emit = defineEmits<{ create: [body: IncidentCreateBody] }>()
const open = defineModel<boolean>({ default: false })

const { t } = useI18n()
const can = useCan()

const title = ref('')
const impact = ref<IncidentImpact>('minor')
const status = ref<IncidentStatus>('investigating')
const message = ref('')
const monitorIds = ref<number[]>([])
const statusPageIds = ref<number[]>([])

// Each opening starts from an empty form.
watch(open, (isOpen) => {
  if (!isOpen)
    return
  title.value = ''
  impact.value = 'minor'
  status.value = 'investigating'
  message.value = ''
  monitorIds.value = []
  statusPageIds.value = []
})

const fields = computed(() => fieldErrors(props.error))
const known = computed(() => Object.keys(props.error?.errors ?? {}).filter(key =>
  [
    'title',
    'impact',
    'status',
    'message',
    'monitor_ids',
    'status_page_ids',
  ].some(root => key === root || key.startsWith(`${root}.`))))

const impactItems = computed(() => INCIDENT_IMPACTS.map(value => ({
  value,
  title: t(`incidents.impact.${value}`),
})))
const statusItems = computed(() => INCIDENT_STATUSES.map(value => ({
  value,
  title: t(`incidents.status.${value}`),
})))

function submit(): void {
  if (props.busy)
    return
  emit('create', {
    title: title.value,
    impact: impact.value,
    status: status.value,
    message: message.value,
    monitor_ids: monitorIds.value,
    status_page_ids: statusPageIds.value,
  })
}
</script>

<template>
  <v-dialog v-model="open" max-width="640" scrollable>
    <v-card :title="t('incidents.newTitle')">
      <v-form @submit.prevent="submit">
        <v-card-text>
          <ProblemAlert :error="error" :known-fields="known" />
          <v-text-field
            v-model="title"
            name="incident_title"
            :label="`${t('incidents.fields.title')} *`"
            maxlength="190"
            autocomplete="off"
            :error-messages="fields.title"
          />
          <v-select
            v-model="impact"
            data-test="incident-impact"
            name="incident_impact"
            :items="impactItems"
            :label="t('incidents.fields.impact')"
            :error-messages="fields.impact"
          />
          <h3 class="text-subtitle-1 mt-2 mb-2">
            {{ t('incidents.create.firstUpdate') }}
          </h3>
          <v-select
            v-model="status"
            data-test="incident-first-status"
            name="incident_status"
            :items="statusItems"
            :label="t('incidents.fields.status')"
            :error-messages="fields.status"
          />
          <v-textarea
            v-model="message"
            name="incident_message"
            :label="`${t('incidents.fields.message')} *`"
            rows="3"
            maxlength="10000"
            :error-messages="fields.message"
          />
          <h3 class="text-subtitle-1 mt-2 mb-2">
            {{ t('incidents.affected.title') }}
          </h3>
          <AffectedPicker
            v-model:monitor-ids="monitorIds"
            v-model:status-page-ids="statusPageIds"
            :slug="slug"
            :can-list-monitors="can('viewMonitors')"
            :monitors-error="fields.monitor_ids"
            :status-pages-error="fields.status_page_ids"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="busy" @click="open = false">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn
            type="submit"
            color="primary"
            variant="flat"
            data-test="incident-create"
            :loading="busy"
          >
            {{ t('incidents.create.submit') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </v-dialog>
</template>
