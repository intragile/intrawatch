<script setup lang="ts">
import type { ApiError } from '../../api'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useSession } from '../../stores/session'

const open = defineModel<boolean>({ default: false })

const { t } = useI18n()
const router = useRouter()
const session = useSession()
const failure = useApiFailure()

const name = ref('')
const busy = ref(false)
const error = ref<ApiError | null>(null)
const fields = computed(() => fieldErrors(error.value))

watch(open, (isOpen) => {
  if (isOpen) {
    name.value = ''
    error.value = null
  }
})

async function create(): Promise<void> {
  if (busy.value || name.value.trim() === '')
    return
  busy.value = true
  error.value = null
  try {
    const created = await unwrap(api.POST('/workspaces', {
      body: {
        name: name.value.trim(),
      },
    }))
    await session.load()
    open.value = false
    await router.push({
      name: 'ws.monitors',
      params: { workspace: created.slug },
    })
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
  <v-dialog v-model="open" max-width="440">
    <v-card :title="t('home.create')">
      <v-form @submit.prevent="create">
        <v-card-text>
          <ProblemAlert :error="error" :known-fields="['name']" />
          <v-text-field
            v-model="name"
            name="workspace_name"
            :label="t('auth.setup.workspaceName')"
            :error-messages="fields.name"
            maxlength="120"
            autofocus
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="busy" @click="open = false">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn type="submit" color="primary" variant="flat" :loading="busy">
            {{ t('common.create') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </v-dialog>
</template>
