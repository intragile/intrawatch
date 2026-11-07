<script setup lang="ts">
defineOptions({ inheritAttrs: false })

const model = defineModel<number | null>({ default: null })

function update(raw: string | number | null): void {
  const text = String(raw ?? '').trim()
  if (text === '') {
    model.value = null
    return
  }
  const n = Number(text)
  if (Number.isFinite(n))
    model.value = Math.trunc(n)
}
</script>

<template>
  <v-text-field
    v-bind="$attrs"
    type="number"
    inputmode="numeric"
    :model-value="model ?? ''"
    @update:model-value="update"
  />
</template>
