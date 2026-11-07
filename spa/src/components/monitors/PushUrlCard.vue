<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Monitor } from '../../api/types'
import { mdiContentCopy, mdiKeyChange } from '@mdi/js'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../api'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'
import ConfirmDialog from '../common/ConfirmDialog.vue'
import ProblemAlert from '../common/ProblemAlert.vue'

const props = defineProps<{ monitor: Monitor }>()
const emit = defineEmits<{ rotated: [pushUrl: string | null] }>()

const { t } = useI18n()
const session = useSession()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const confirming = ref(false)
const busy = ref(false)
const error = ref<ApiError | null>(null)

const canManage = computed(() => can('manageMonitors'))
const url = computed(() => props.monitor.push_url)
const curl = computed(() => url.value === null
  ? ''
  : `curl -fsS --retry 3 "${url.value}"`)

async function copy(value: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(value)
    notify(t('common.copied'))
  }
  catch {
    notify(t('common.copyFailed'), 'error')
  }
}

async function rotate(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const result = await bodyOf(api.POST('/workspaces/{workspace}/monitors/{monitor}/push-token', {
      params: {
        path: { workspace: session.current?.slug ?? '', monitor: props.monitor.id },
      },
    }))
    confirming.value = false
    emit('rotated', result.push_url)
    notify(t('monitorDetail.push.rotated'))
  }
  catch (e) {
    confirming.value = false
    error.value = failure(e)
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <v-card :title="t('monitorDetail.push.title')" variant="outlined">
    <v-card-text>
      <ProblemAlert :error="error" />
      <template v-if="url !== null">
        <v-text-field
          :model-value="url"
          :label="t('monitorDetail.push.url')"
          readonly
          density="compact"
          data-test="push-url"
          :append-inner-icon="mdiContentCopy"
          @click:append-inner="copy(url)"
        />
        <p class="text-body-2 mb-1">
          {{ t('monitorDetail.push.example') }}
        </p>
        <pre class="text-body-2 pa-2 rounded bg-surface-variant overflow-x-auto" data-test="push-curl">{{ curl }}</pre>
        <p class="text-caption mt-2">
          {{ t('monitorDetail.push.secret') }}
        </p>
      </template>
      <p v-else data-test="push-has-token">
        {{ monitor.has_push_token ? t('monitorDetail.push.hasToken') : t('monitorDetail.push.noToken') }}
      </p>
    </v-card-text>
    <v-card-actions v-if="url !== null">
      <v-btn variant="text" :prepend-icon="mdiContentCopy" data-test="copy" @click="copy(url)">
        {{ t('common.copy') }}
      </v-btn>
      <v-btn v-if="canManage" variant="text" color="warning" :prepend-icon="mdiKeyChange" data-test="rotate" @click="confirming = true">
        {{ t('monitorDetail.push.rotate') }}
      </v-btn>
    </v-card-actions>
    <ConfirmDialog
      v-model="confirming"
      :title="t('monitorDetail.push.rotateTitle')"
      :text="t('monitorDetail.push.rotateText')"
      :confirm-label="t('monitorDetail.push.rotate')"
      :loading="busy"
      destructive
      @confirm="rotate"
    />
  </v-card>
</template>
