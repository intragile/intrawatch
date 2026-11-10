<script setup lang="ts">
import { computed } from 'vue'
import { DEFAULT_TAG_COLOR, isHexColor, tagTextColor } from '../../utils/tagColor'

const props = withDefaults(defineProps<{
  name: string
  /** The tag's `#rrggbb` fill; anything else shows the default tag color. */
  color: string
  /** The value a monitor gives the tag, shown as `name: value`. */
  value?: string | null
  size?: 'x-small' | 'small' | 'default'
}>(), {
  value: null,
  size: 'small',
})

const fill = computed(() => isHexColor(props.color)
  ? props.color
  : DEFAULT_TAG_COLOR)
const style = computed(() => ({
  backgroundColor: fill.value,
  color: tagTextColor(fill.value),
}))
const label = computed(() => props.value
  ? `${props.name}: ${props.value}`
  : props.name)
</script>

<template>
  <v-chip :size="size" variant="flat" :style="style" class="tag-chip">
    {{ label }}
  </v-chip>
</template>

<style scoped>
.tag-chip {
  max-width: 100%;
}
</style>
