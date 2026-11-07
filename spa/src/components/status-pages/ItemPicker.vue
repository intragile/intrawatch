<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Monitor } from '../../api/types'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../../api'
import { bodyOf } from '../../utils/cursorPage'
import { asApiError } from '../../utils/errors'
import ProblemAlert from '../common/ProblemAlert.vue'

export type PickedMonitor = Pick<Monitor, 'id' | 'name'>

const props = withDefaults(defineProps<{
  slug: string
  label: string
  testId?: string
  disabled?: boolean
  /** Monitors already on the page; a monitor can appear only once. */
  excludeIds?: number[]
}>(), {
  testId: undefined,
  disabled: false,
  excludeIds: () => [],
})

const emit = defineEmits<{ add: [monitor: PickedMonitor] }>()

/** How many monitors one search loads. */
const OPTIONS = 20

const selected = ref<PickedMonitor | null>(null)
const found = ref<PickedMonitor[]>([])
const search = ref('')
const searching = ref(false)
const error = ref<ApiError | null>(null)
let seq = 0
let timer: ReturnType<typeof setTimeout> | undefined

async function load(q: string): Promise<void> {
  const mine = ++seq
  searching.value = true
  error.value = null
  try {
    const result = await bodyOf(api.GET('/workspaces/{workspace}/monitors', {
      params: {
        path: { workspace: props.slug },
        query: {
          q: q.trim() === ''
            ? undefined
            : q.trim(),
          limit: OPTIONS,
        },
      },
    }))
    if (mine === seq)
      found.value = result.data.map(m => ({ id: m.id, name: m.name }))
  }
  catch (e) {
    error.value = asApiError(e)
  }
  finally {
    if (mine === seq)
      searching.value = false
  }
}

watch(search, (q) => {
  clearTimeout(timer)
  timer = setTimeout(() => void load(q), 250)
})
onBeforeUnmount(() => clearTimeout(timer))

const options = computed(() => found.value.filter(m => !props.excludeIds.includes(m.id)))

function pick(monitor: PickedMonitor | null): void {
  if (monitor === null)
    return
  emit('add', monitor)
  selected.value = null
  search.value = ''
}
</script>

<template>
  <ProblemAlert :error="error" />
  <v-autocomplete
    v-model:search="search"
    :model-value="selected"
    :data-test="testId"
    :items="options"
    item-title="name"
    item-value="id"
    return-object
    no-filter
    clearable
    hide-details="auto"
    density="comfortable"
    :loading="searching"
    :label="label"
    :disabled="disabled"
    @update:model-value="pick"
    @focus="load(search)"
  />
</template>
