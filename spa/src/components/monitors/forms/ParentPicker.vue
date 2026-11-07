<script setup lang="ts">
import type { Monitor } from '../../../api/types'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, unwrap, useCursorList } from '../../../api'
import { useSession } from '../../../stores/session'
import { bodyOf } from '../../../utils/cursorPage'

const props = defineProps<{
  /** The monitor being edited, which can never be its own parent. */
  excludeId: number | null
  error?: string
}>()

const model = defineModel<number | null>({ default: null })
const { t } = useI18n()
const session = useSession()

const PAGE_LIMIT = 50
const SEARCH_DEBOUNCE_MS = 250

const search = ref('')
const slug = computed(() => session.current?.slug ?? '')

const list = useCursorList<Monitor>(cursor => bodyOf(api.GET('/workspaces/{workspace}/monitors', {
  params: {
    path: { workspace: slug.value },
    query: {
      type: 'group',
      q: search.value.trim() === ''
        ? undefined
        : search.value.trim(),
      cursor: cursor ?? undefined,
      limit: PAGE_LIMIT,
    },
  },
})))

// The current parent may not be on the first page of groups, so it is fetched on its own.
const current = ref<{ id: number, name: string } | null>(null)

const items = computed(() => {
  const groups = list.items.value
    .filter(m => m.id !== props.excludeId)
    .map(m => ({ value: m.id, title: m.name }))
  const selected = current.value
  if (selected && !groups.some(g => g.value === selected.id))
    groups.unshift({ value: selected.id, title: selected.name })
  return groups
})

async function resolveCurrent(id: number | null): Promise<void> {
  if (id === null) {
    current.value = null
    return
  }
  const known = list.items.value.find(m => m.id === id)
  if (known) {
    current.value = { id, name: known.name }
    return
  }
  if (current.value?.id === id)
    return
  try {
    const parent = await unwrap<Monitor>(api.GET('/workspaces/{workspace}/monitors/{monitor}', {
      params: {
        path: { workspace: slug.value, monitor: id },
      },
    }))
    current.value = { id, name: parent.name }
  }
  catch {
    current.value = { id, name: `#${id}` }
  }
}

let timer: ReturnType<typeof setTimeout> | null = null

function onSearch(value: string): void {
  // Selecting an item reports its title as the search text; only typed text is a query.
  if (value === current.value?.name || value === search.value)
    return
  search.value = value
  if (timer)
    clearTimeout(timer)
  timer = setTimeout(() => void list.reload(), SEARCH_DEBOUNCE_MS)
}

watch(model, id => void resolveCurrent(id), { immediate: true })
void list.loadMore()
onBeforeUnmount(() => {
  if (timer)
    clearTimeout(timer)
})
</script>

<template>
  <v-autocomplete
    v-model="model"
    name="parent_id"
    :items="items"
    :loading="list.loading.value"
    :label="t('monitorEditor.parent.label')"
    :hint="t('monitorEditor.parent.hint')"
    persistent-hint
    clearable
    no-filter
    :error-messages="error"
    @update:search="onSearch"
  >
    <template v-if="list.hasMore.value && !list.loading.value" #append-item>
      <v-list-item :title="t('common.loadMore')" data-test="parent-load-more" @click="list.loadMore()" />
    </template>
  </v-autocomplete>
</template>
