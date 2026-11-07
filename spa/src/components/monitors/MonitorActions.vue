<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Monitor } from '../../api/types'
import { mdiPause, mdiPencil, mdiPlay, mdiRefresh } from '@mdi/js'
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap } from '../../api'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useWorkspaceLive } from '../../live/useWorkspaceLive'
import { useSession } from '../../stores/session'
import { bodyOf } from '../../utils/cursorPage'
import ProblemAlert from '../common/ProblemAlert.vue'

const props = defineProps<{
  monitor: Monitor
  /** Whether the monitor type is checked on demand. */
  checkable: boolean
}>()

const emit = defineEmits<{ updated: [monitor: Monitor] }>()

/** How long "Check now" stays disabled when no result arrives. */
const CHECK_COOLDOWN_MS = 30_000

const { t } = useI18n()
const session = useSession()
const live = useWorkspaceLive()
const can = useCan()
const failure = useApiFailure()

const canManage = computed(() => can('manageMonitors'))
const slug = computed(() => session.current?.slug ?? '')
const busy = ref(false)
const waiting = ref(false)
const error = ref<ApiError | null>(null)

let timer: ReturnType<typeof setTimeout> | null = null

function release(): void {
  waiting.value = false
  if (timer !== null)
    clearTimeout(timer)
  timer = null
}

const stop = live.on('checks', (e) => {
  if (waiting.value && e.checks.some(c => c.monitor_id === props.monitor.id))
    release()
})
onBeforeUnmount(() => {
  stop()
  release()
})

async function toggle(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const path = props.monitor.active
      ? '/workspaces/{workspace}/monitors/{monitor}/pause'
      : '/workspaces/{workspace}/monitors/{monitor}/resume'
    emit('updated', await unwrap<Monitor>(api.POST(path, {
      params: {
        path: { workspace: slug.value, monitor: props.monitor.id },
      },
    })))
  }
  catch (e) {
    error.value = failure(e)
  }
  finally {
    busy.value = false
  }
}

async function checkNow(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await bodyOf(api.POST('/workspaces/{workspace}/monitors/{monitor}/check', {
      params: {
        path: { workspace: slug.value, monitor: props.monitor.id },
      },
    }))
    waiting.value = true
    timer = setTimeout(release, CHECK_COOLDOWN_MS)
  }
  catch (e) {
    error.value = failure(e)
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="canManage">
    <ProblemAlert :error="error" />
    <div class="d-flex flex-wrap ga-2">
      <v-btn
        v-if="checkable"
        data-test="check"
        :prepend-icon="mdiRefresh"
        variant="tonal"
        :loading="busy && !waiting"
        :disabled="waiting || !monitor.active"
        @click="checkNow"
      >
        {{ waiting ? t('monitorDetail.actions.checking') : t('monitorDetail.actions.check') }}
      </v-btn>
      <v-btn
        :data-test="monitor.active ? 'pause' : 'resume'"
        :prepend-icon="monitor.active ? mdiPause : mdiPlay"
        variant="tonal"
        :disabled="busy"
        @click="toggle"
      >
        {{ monitor.active ? t('monitorDetail.actions.pause') : t('monitorDetail.actions.resume') }}
      </v-btn>
      <v-btn
        data-test="edit"
        :prepend-icon="mdiPencil"
        variant="tonal"
        :to="{ name: 'ws.monitor.edit', params: { workspace: slug, monitor: String(monitor.id) } }"
      >
        {{ t('monitorDetail.actions.edit') }}
      </v-btn>
    </div>
  </div>
</template>
