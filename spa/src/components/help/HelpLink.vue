<script setup lang="ts">
import type { LocalizedAnchor } from './manual'
import { mdiHelpCircleOutline } from '@mdi/js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { isLocale } from '../../i18n'
import { chapterLocation } from './manual'

/**
 * The "?" next to a screen title: opens the manual chapter (or one section of it) that explains
 * the screen. Usually bound to an entry of SCREEN_HELP: `v-bind="screenHelp('ws.alerts')"`.
 */
const props = defineProps<{
  slug: string
  /** A heading anchor of the chapter; a per-locale map, since anchors come from translated headings. */
  anchor?: string | LocalizedAnchor
  /** Replaces the chapter title in the label and the tooltip. */
  label?: string
}>()

const { t, te, locale } = useI18n()

const anchor = computed(() => {
  if (props.anchor === undefined || typeof props.anchor === 'string')
    return props.anchor
  // A locale without its own anchor opens the chapter at the top rather than at a wrong heading.
  return isLocale(locale.value)
    ? props.anchor[locale.value]
    : undefined
})

const text = computed(() => {
  const titleKey = `help.chapterTitles.${props.slug}`
  const title = props.label ?? (te(titleKey)
    ? t(titleKey)
    : t('help.title'))
  return t('help.link', { title })
})
</script>

<template>
  <v-tooltip :text="text" location="bottom">
    <template #activator="{ props: activator }">
      <v-btn
        v-bind="activator"
        icon
        variant="text"
        size="small"
        class="help-link text-medium-emphasis"
        data-test="help-link"
        :aria-label="text"
        :to="chapterLocation(slug, anchor)"
      >
        <v-icon :icon="mdiHelpCircleOutline" />
      </v-btn>
    </template>
  </v-tooltip>
</template>
