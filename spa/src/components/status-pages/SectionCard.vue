<script setup lang="ts">
import type { LayoutSection } from './model'
import { mdiArrowDown, mdiArrowUp, mdiClose, mdiDragVertical, mdiTrashCanOutline } from '@mdi/js'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { moved } from './model'

const props = defineProps<{
  index: number
  first: boolean
  last: boolean
  disabled: boolean
  /** Display label per monitor id; ids without one show as "Monitor #id". */
  names: Record<number, string>
  /** Field errors relative to this section (`name`, `items.0.display_name`). */
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  moveSection: [direction: -1 | 1]
  removeSection: []
  sectionDragStart: []
  sectionDragEnd: []
  /** Moves an item one step; past the edge it crosses into the neighbouring section. */
  moveItem: [from: number, direction: -1 | 1]
}>()

const section = defineModel<LayoutSection>({ required: true })
const { t } = useI18n()

const dragFrom = ref<number | null>(null)

function dropOn(target: number): void {
  const from = dragFrom.value
  dragFrom.value = null
  if (from === null || from === target)
    return
  section.value = {
    ...section.value,
    items: moved(section.value.items, from, target),
  }
}

function removeItem(i: number): void {
  section.value = {
    ...section.value,
    items: section.value.items.filter((_, n) => n !== i),
  }
}

function monitorLabel(id: number): string {
  return props.names[id] ?? t('statusPages.layout.monitorById', { id })
}

const itemFirst = (i: number) => props.first && i === 0
const itemLast = (i: number) => props.last && i === section.value.items.length - 1
</script>

<template>
  <v-card variant="outlined" class="mb-3" :data-test="`section-${index}`">
    <v-card-text>
      <div class="d-flex align-center ga-1 mb-2">
        <span
          :draggable="!disabled"
          class="drag-handle"
          aria-hidden="true"
          @dragstart="emit('sectionDragStart')"
          @dragend="emit('sectionDragEnd')"
        >
          <v-icon :icon="mdiDragVertical" class="text-medium-emphasis" />
        </span>
        <v-text-field
          v-model="section.name"
          :name="`section_name_${index}`"
          :label="`${t('statusPages.layout.sectionName')} *`"
          maxlength="190"
          density="comfortable"
          hide-details="auto"
          autocomplete="off"
          :disabled="disabled"
          :error-messages="errors.name"
        />
        <template v-if="!disabled">
          <v-btn
            icon
            variant="text"
            size="small"
            :data-test="`section-up-${index}`"
            :disabled="first"
            :aria-label="t('statusPages.layout.moveSectionUp', { name: section.name })"
            @click="emit('moveSection', -1)"
          >
            <v-icon :icon="mdiArrowUp" />
          </v-btn>
          <v-btn
            icon
            variant="text"
            size="small"
            :data-test="`section-down-${index}`"
            :disabled="last"
            :aria-label="t('statusPages.layout.moveSectionDown', { name: section.name })"
            @click="emit('moveSection', 1)"
          >
            <v-icon :icon="mdiArrowDown" />
          </v-btn>
          <v-btn
            icon
            variant="text"
            size="small"
            color="error"
            :data-test="`section-remove-${index}`"
            :aria-label="t('statusPages.layout.removeSection', { name: section.name })"
            @click="emit('removeSection')"
          >
            <v-icon :icon="mdiTrashCanOutline" />
          </v-btn>
        </template>
      </div>
      <v-text-field
        v-model="section.description"
        :name="`section_description_${index}`"
        :label="t('statusPages.layout.sectionDescription')"
        maxlength="500"
        density="comfortable"
        hide-details="auto"
        class="mb-2"
        :disabled="disabled"
        :error-messages="errors.description"
      />

      <ul class="item-list" :aria-label="t('statusPages.layout.items')">
        <li
          v-for="(item, i) in section.items"
          :key="item.key"
          class="item-row d-flex align-center ga-1 py-1"
          :data-test="`item-${index}-${i}`"
          @dragover.prevent
          @drop.prevent="dropOn(i)"
        >
          <span
            :draggable="!disabled"
            class="drag-handle"
            aria-hidden="true"
            @dragstart="dragFrom = i"
            @dragend="dragFrom = null"
          >
            <v-icon :icon="mdiDragVertical" class="text-medium-emphasis" />
          </span>
          <v-text-field
            v-model="item.display_name"
            :name="`item_display_name_${index}_${i}`"
            :label="monitorLabel(item.monitor_id)"
            :placeholder="monitorLabel(item.monitor_id)"
            persistent-placeholder
            maxlength="190"
            density="compact"
            hide-details="auto"
            :disabled="disabled"
            :error-messages="errors[`items.${i}.display_name`] ?? errors[`items.${i}.monitor_id`]"
          />
          <v-checkbox
            v-model="item.show_uptime"
            :label="t('statusPages.layout.showUptime')"
            density="compact"
            hide-details
            :disabled="disabled"
          />
          <v-checkbox
            v-model="item.expand_children"
            :label="t('statusPages.layout.expandChildren')"
            density="compact"
            hide-details
            :disabled="disabled"
          />
          <template v-if="!disabled">
            <v-btn
              icon
              variant="text"
              size="small"
              :data-test="`item-up-${index}-${i}`"
              :disabled="itemFirst(i)"
              :aria-label="t('statusPages.layout.moveItemUp', { name: monitorLabel(item.monitor_id) })"
              @click="emit('moveItem', i, -1)"
            >
              <v-icon :icon="mdiArrowUp" />
            </v-btn>
            <v-btn
              icon
              variant="text"
              size="small"
              :data-test="`item-down-${index}-${i}`"
              :disabled="itemLast(i)"
              :aria-label="t('statusPages.layout.moveItemDown', { name: monitorLabel(item.monitor_id) })"
              @click="emit('moveItem', i, 1)"
            >
              <v-icon :icon="mdiArrowDown" />
            </v-btn>
            <v-btn
              icon
              variant="text"
              size="small"
              :data-test="`item-remove-${index}-${i}`"
              :aria-label="t('statusPages.layout.removeItem', { name: monitorLabel(item.monitor_id) })"
              @click="removeItem(i)"
            >
              <v-icon :icon="mdiClose" />
            </v-btn>
          </template>
        </li>
      </ul>
      <p v-if="section.items.length === 0" class="text-caption text-medium-emphasis my-2">
        {{ t('statusPages.layout.noItems') }}
      </p>

      <slot name="picker" />
    </v-card-text>
  </v-card>
</template>

<style scoped>
.drag-handle {
  cursor: grab;
}

.item-list {
  list-style: none;
  padding: 0;
}
</style>
