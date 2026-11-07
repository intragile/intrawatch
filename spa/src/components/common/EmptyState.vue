<script setup lang="ts">
defineProps<{
  /** An `@mdi/js` path. */
  icon?: string
  title: string
  text?: string
}>()
</script>

<template>
  <v-empty-state :icon="icon" :title="title" :text="text">
    <template v-if="$slots.actions" #actions>
      <slot name="actions" />
    </template>
  </v-empty-state>
</template>
