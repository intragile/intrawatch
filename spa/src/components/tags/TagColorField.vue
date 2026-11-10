<script setup lang="ts">
import { mdiCheck } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { isHexColor, TAG_PALETTE, tagTextColor } from '../../utils/tagColor'

const props = defineProps<{
  /** The `name` of the hex input; also prefixes the swatches' `data-test`. */
  name: string
  /** A server message for the color. */
  error?: string
}>()

const model = defineModel<string>({ required: true })
const { t } = useI18n()

const current = computed(() => model.value.trim().toLowerCase())
const valid = computed(() => isHexColor(current.value))
const message = computed(() => props.error ?? (valid.value
  ? undefined
  : t('tags.color.invalid')))
</script>

<template>
  <fieldset class="tag-color">
    <legend class="text-subtitle-2 mb-2">
      {{ t('tags.color.label') }}
    </legend>
    <div class="swatches mb-3" role="group" :aria-label="t('tags.color.presets')">
      <button
        v-for="preset in TAG_PALETTE"
        :key="preset.key"
        type="button"
        class="swatch"
        :data-test="`${name}-${preset.key}`"
        :style="{ backgroundColor: preset.hex, color: tagTextColor(preset.hex) }"
        :aria-label="t(`tags.color.presetNames.${preset.key}`)"
        :aria-pressed="current === preset.hex"
        :title="t(`tags.color.presetNames.${preset.key}`)"
        @click="model = preset.hex"
      >
        <v-icon v-if="current === preset.hex" :icon="mdiCheck" size="small" />
      </button>
    </div>
    <v-text-field
      v-model="model"
      :name="name"
      :label="t('tags.color.custom')"
      :error-messages="message"
      maxlength="7"
      autocomplete="off"
      spellcheck="false"
      density="compact"
      class="hex"
    >
      <template #prepend-inner>
        <span
          class="preview"
          aria-hidden="true"
          :style="{ backgroundColor: valid ? current : 'transparent' }"
        />
      </template>
    </v-text-field>
  </fieldset>
</template>

<style scoped>
.tag-color {
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}

.swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.swatch {
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid transparent;
  outline-offset: 2px;
  cursor: pointer;
}

.swatch[aria-pressed='true'] {
  border-color: rgb(var(--v-theme-on-surface));
}

.swatch:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
}

.hex {
  max-width: 14rem;
}

.preview {
  display: inline-block;
  width: 1.25rem;
  height: 1.25rem;
  border-radius: 4px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.38);
}
</style>
