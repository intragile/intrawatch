<script setup lang="ts">
import type { Tag } from '../../../api/types'
import type { TagAssignment } from './model'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  tags: Tag[]
  loading?: boolean
  /** A message about the tag list as a whole (for example too many tags). */
  error?: string
  /** Validation messages keyed relative to `tags.` (e.g. `0.id`). */
  errors: Record<string, string>
}>()

const model = defineModel<TagAssignment[]>({
  default: () => [],
})
const { t } = useI18n()

const items = computed(() => props.tags.map(tag => ({ value: tag.id, title: tag.name })))
const selected = computed(() => model.value.map(a => a.id))

function nameOf(id: number): string {
  return props.tags.find(tag => tag.id === id)?.name ?? `#${id}`
}

function select(ids: number[]): void {
  model.value = ids.map(id => model.value.find(a => a.id === id) ?? { id, value: null })
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
  <v-select
    :model-value="selected"
    name="tags"
    :items="items"
    multiple
    chips
    closable-chips
    :loading="loading"
    :label="t('monitorEditor.tags.label')"
    :hint="tags.length === 0 && !loading ? t('monitorEditor.tags.none') : undefined"
    :persistent-hint="tags.length === 0 && !loading"
    :error-messages="error"
    @update:model-value="select"
  />
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
