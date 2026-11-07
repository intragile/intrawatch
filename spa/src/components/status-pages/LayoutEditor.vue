<script setup lang="ts">
import type { PickedMonitor } from './ItemPicker.vue'
import type { LayoutSection } from './model'
import { mdiPlus } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../api'
import ItemPicker from './ItemPicker.vue'
import { moved, newItem, newSection } from './model'
import SectionCard from './SectionCard.vue'

const props = defineProps<{
  slug: string
  disabled: boolean
  canListMonitors: boolean
  /** Field errors keyed by the API's dotted path, e.g. `sections.0.name`. */
  errors: Record<string, string>
}>()

/** Monitors whose names are looked up individually; beyond this they show as ids. */
const NAME_LOOKUPS = 25

const sections = defineModel<LayoutSection[]>({ required: true })
const { t } = useI18n()

const names = ref<Record<number, string>>({})

const placedIds = computed(() => sections.value.flatMap(s => s.items.map(i => i.monitor_id)))

async function resolveNames(): Promise<void> {
  if (!props.canListMonitors)
    return
  const missing = [
    ...new Set(placedIds.value),
  ].filter(id => names.value[id] === undefined).slice(0, NAME_LOOKUPS)
  await Promise.all(missing.map(async (id) => {
    try {
      const result = await api.GET('/workspaces/{workspace}/monitors/{monitor}', {
        params: {
          path: { workspace: props.slug, monitor: id },
        },
      })
      const data = result.data?.data
      if (data)
        names.value = { ...names.value, [data.id]: data.name }
    }
    catch {
      // A name that cannot be resolved falls back to the id.
    }
  }))
}

watch(() => placedIds.value.join(','), () => void resolveNames(), { immediate: true })

function sectionErrors(index: number): Record<string, string> {
  const head = `sections.${index}.`
  const out: Record<string, string> = {}
  for (const [key, message] of Object.entries(props.errors)) {
    if (key.startsWith(head))
      out[key.slice(head.length)] = message
  }
  return out
}

function addSection(): void {
  sections.value = [
    ...sections.value,
    newSection(),
  ]
}

function removeSection(index: number): void {
  sections.value = sections.value.filter((_, i) => i !== index)
}

function moveSection(index: number, direction: -1 | 1): void {
  sections.value = moved(sections.value, index, index + direction)
}

function update(index: number, next: LayoutSection): void {
  sections.value = sections.value.map((s, i) => i === index
    ? next
    : s)
}

function moveItem(sectionIndex: number, from: number, direction: -1 | 1): void {
  const list = sections.value
  const section = list[sectionIndex]
  if (!section)
    return
  const to = from + direction
  if (to >= 0 && to < section.items.length) {
    update(sectionIndex, {
      ...section,
      items: moved(section.items, from, to),
    })
    return
  }
  // Past the edge the item joins the neighbouring section, next to where it left.
  const neighbour = list[sectionIndex + direction]
  const item = section.items[from]
  if (!neighbour || !item)
    return
  sections.value = list.map((s, i) => {
    if (i === sectionIndex) {
      return {
        ...s,
        items: s.items.filter((_, n) => n !== from),
      }
    }
    if (i === sectionIndex + direction) {
      return {
        ...s,
        items: direction === -1
          ? [...s.items, item]
          : [item, ...s.items],
      }
    }
    return s
  })
}

function addItem(sectionIndex: number, monitor: PickedMonitor): void {
  const section = sections.value[sectionIndex]
  if (!section || placedIds.value.includes(monitor.id))
    return
  names.value = { ...names.value, [monitor.id]: monitor.name }
  update(sectionIndex, {
    ...section,
    items: [
      ...section.items,
      newItem(monitor.id),
    ],
  })
}

const dragSection = ref<number | null>(null)

function dropSection(target: number): void {
  const from = dragSection.value
  dragSection.value = null
  if (from !== null && from !== target)
    sections.value = moved(sections.value, from, target)
}
</script>

<template>
  <div>
    <div
      v-for="(section, index) in sections"
      :key="section.key"
      @dragover.prevent
      @drop.prevent="dropSection(index)"
    >
      <SectionCard
        :model-value="section"
        :index="index"
        :first="index === 0"
        :last="index === sections.length - 1"
        :disabled="disabled"
        :names="names"
        :errors="sectionErrors(index)"
        @update:model-value="update(index, $event)"
        @move-section="moveSection(index, $event)"
        @remove-section="removeSection(index)"
        @section-drag-start="dragSection = index"
        @move-item="(from, direction) => moveItem(index, from, direction)"
      >
        <template #picker>
          <ItemPicker
            v-if="canListMonitors"
            :test-id="`item-picker-${index}`"
            :slug="slug"
            :label="t('statusPages.layout.addMonitor')"
            :disabled="disabled"
            :exclude-ids="placedIds"
            @add="addItem(index, $event)"
          />
        </template>
      </SectionCard>
    </div>
    <p v-if="sections.length === 0" class="text-body-2 text-medium-emphasis mb-3">
      {{ t('statusPages.layout.noSections') }}
    </p>
    <v-btn
      v-if="!disabled"
      data-test="section-add"
      variant="tonal"
      :prepend-icon="mdiPlus"
      @click="addSection"
    >
      {{ t('statusPages.layout.addSection') }}
    </v-btn>
  </div>
</template>
