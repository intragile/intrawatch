<script setup lang="ts">
import type { Tag } from '../../../api/types'
import type { TagAssignment } from './model'
import { mdiTagPlusOutline } from '@mdi/js'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  tags: Tag[]
  loading?: boolean
  /** A message about the tag list as a whole (for example too many tags). */
  error?: string
  /** Validation messages keyed relative to `tags.` (e.g. `0.id`). */
  errors: Record<string, string>
  /**
   * Creates a tag in the workspace and resolves with it; absent when the user may not create tags.
   * A rejection carries the message to show.
   */
  create?: (name: string) => Promise<Tag>
}>()

const model = defineModel<TagAssignment[]>({
  default: () => [],
})
const { t } = useI18n()

const search = ref('')
const creating = ref(false)
const createError = ref<string | null>(null)

const items = computed(() => props.tags.map(tag => ({ value: tag.id, title: tag.name })))
const selected = computed(() => model.value.map(a => a.id))

/** The typed name, when it is new: no tag has it yet, case aside. */
const newName = computed(() => {
  const name = search.value.trim()
  if (name === '' || props.create === undefined)
    return null
  const lower = name.toLocaleLowerCase()
  return props.tags.some(tag => tag.name.toLocaleLowerCase() === lower)
    ? null
    : name
})

const hint = computed(() => {
  if (props.tags.length > 0 || props.loading)
    return undefined
  return props.create === undefined
    ? t('monitorEditor.tags.none')
    : t('monitorEditor.tags.noneCreate')
})

function nameOf(id: number): string {
  return props.tags.find(tag => tag.id === id)?.name ?? `#${id}`
}

function select(ids: number[]): void {
  model.value = ids.map(id => model.value.find(a => a.id === id) ?? { id, value: null })
}

async function createTag(): Promise<void> {
  const name = newName.value
  if (name === null || props.create === undefined || creating.value)
    return
  creating.value = true
  createError.value = null
  try {
    const tag = await props.create(name)
    select([...selected.value, tag.id])
    search.value = ''
  }
  catch (e) {
    createError.value = e instanceof Error && e.message !== ''
      ? e.message
      : t('monitorEditor.tags.createFailed')
  }
  finally {
    creating.value = false
  }
}

/** Enter on a new name creates it; otherwise Enter keeps its usual meaning (pick the highlighted item). */
function onEnter(event: KeyboardEvent): void {
  if (newName.value === null)
    return
  event.preventDefault()
  void createTag()
}

function setValue(id: number, value: string): void {
  model.value = model.value.map(a => a.id === id
    ? {
        ...a,
        value: value.trim() === ''
          ? null
          : value,
      }
    : a)
}

function errorAt(index: number): string | undefined {
  return props.errors[`${index}.id`] ?? props.errors[`${index}.value`] ?? props.errors[String(index)]
}
</script>

<template>
  <v-autocomplete
    v-model:search="search"
    :model-value="selected"
    name="tags"
    :items="items"
    multiple
    chips
    closable-chips
    :loading="loading || creating"
    :label="t('monitorEditor.tags.label')"
    :hint="hint"
    :persistent-hint="hint !== undefined"
    :error-messages="createError ?? error"
    :hide-no-data="newName === null"
    @update:model-value="select"
    @keydown.enter="onEnter"
  >
    <template v-if="newName !== null" #prepend-item>
      <v-list-item
        data-test="tag-create"
        :prepend-icon="mdiTagPlusOutline"
        :title="t('monitorEditor.tags.create', { name: newName })"
        :disabled="creating"
        @click="createTag"
      />
    </template>
    <template #no-data>
      <span />
    </template>
  </v-autocomplete>
  <v-text-field
    v-for="(assignment, i) in model"
    :key="assignment.id"
    :model-value="assignment.value ?? ''"
    :name="`tag_value_${assignment.id}`"
    :label="t('monitorEditor.tags.value', { name: nameOf(assignment.id) })"
    :error-messages="errorAt(i)"
    density="compact"
    maxlength="120"
    autocomplete="off"
    @update:model-value="setValue(assignment.id, $event)"
  />
</template>
