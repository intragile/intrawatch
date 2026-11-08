<script setup lang="ts">
import type { ApiError } from '../../api'
import type { ImportRun } from '../../api/types'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, fieldErrors, unwrap } from '../../api'
import { useApiFailure } from '../../composables/useApiFailure'
import { readRuntimeConfig } from '../../config'
import ProblemAlert from '../common/ProblemAlert.vue'

const props = defineProps<{ workspace: string }>()
const emit = defineEmits<{ uploaded: [run: ImportRun] }>()

const { t } = useI18n()
const failure = useApiFailure()

const maxMb = readRuntimeConfig().import.maxUploadMb
const file = ref<File | null>(null)
const history = ref(true)
const paused = ref(false)
const busy = ref(false)
const error = ref<ApiError | null>(null)
const localError = ref('')

const fileMessage = computed(() => localError.value || fieldErrors(error.value).file || '')

function onFile(event: Event): void {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  localError.value = ''
  error.value = null
}

async function upload(): Promise<void> {
  if (busy.value)
    return
  error.value = null
  const chosen = file.value
  if (!chosen) {
    localError.value = t('import.upload.noFile')
    return
  }
  if (chosen.size > maxMb * 1024 * 1024) {
    localError.value = t('import.upload.tooLarge', { max: maxMb })
    return
  }
  localError.value = ''

  // Multipart booleans are sent as 1/0, which is what the server's boolean rule accepts.
  const form = new FormData()
  form.append('file', chosen)
  form.append('history', history.value ? '1' : '0')
  form.append('paused', paused.value ? '1' : '0')

  busy.value = true
  try {
    const created = await unwrap<ImportRun>(api.POST('/workspaces/{workspace}/imports', {
      params: {
        path: { workspace: props.workspace },
      },
      // The typed body only describes the fields; the serializer sends the FormData as is.
      body: { file: '' },
      bodySerializer: () => form,
    }))
    file.value = null
    emit('uploaded', created)
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
  <v-card class="mb-4" max-width="960">
    <v-card-title tag="h2" class="text-h6">
      {{ t('import.upload.title') }}
    </v-card-title>
    <v-form @submit.prevent="upload">
      <v-card-text>
        <ProblemAlert :error="error" :known-fields="['file']" />
        <div class="mb-3">
          <label for="import-file" class="text-subtitle-2 d-block mb-1">{{ t('import.upload.file') }}</label>
          <input
            id="import-file"
            name="import_file"
            type="file"
            accept=".db,.sqlite,.sqlite3,.json"
            aria-describedby="import-file-hint"
            :aria-invalid="fileMessage !== ''"
            @change="onFile"
          >
          <p id="import-file-hint" class="text-medium-emphasis mt-1">
            {{ t('import.upload.fileHint', { max: maxMb }) }}
          </p>
          <p v-if="fileMessage" class="text-error" role="alert">
            {{ fileMessage }}
          </p>
        </div>
        <v-checkbox v-model="history" name="import_history" :label="t('import.upload.history')" density="compact" hide-details />
        <v-checkbox v-model="paused" name="import_paused" :label="t('import.upload.paused')" density="compact" hide-details />
        <p class="text-medium-emphasis mt-3">
          {{ t('import.upload.help') }}
        </p>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn data-test="import-upload" type="submit" color="primary" variant="flat" :loading="busy">
          {{ t('import.upload.submit') }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </v-card>
</template>
