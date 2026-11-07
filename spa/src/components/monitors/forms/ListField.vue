<script setup lang="ts">
import { computed } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  /** Entries are whole numbers rather than text. */
  numeric?: boolean
}>()

const model = defineModel<Array<string | number> | null>({ default: null })

const shown = computed(() => (model.value ?? []).map(String))

function update(next: Array<string | number>): void {
  const items = next.map(v => String(v).trim()).filter(v => v !== '')
  if (!props.numeric) {
    model.value = items
    return
  }
  model.value = items
    .map(Number)
    .filter(n => Number.isFinite(n))
    .map(Math.trunc)
}
</script>

<template>
  <v-combobox
    v-bind="$attrs"
    :model-value="shown"
    multiple
    chips
    closable-chips
    @update:model-value="update"
  />
</template>
