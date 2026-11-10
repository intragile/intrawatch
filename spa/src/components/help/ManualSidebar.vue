<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import { prefersReducedMotion } from './manual'

interface Heading {
  anchor: string
  text: string
  level: number
}

defineProps<{
  headings: Heading[]
}>()

const { t } = useI18n()
// Below md the contents collapse into a panel above the chapter.
const { smAndDown: mobile } = useDisplay()

/** Scrolls to a heading without touching the route (a hash would be read as a navigation). */
function go(anchor: string): void {
  const target = document.getElementById(anchor)
  if (!target)
    return
  target.scrollIntoView?.({
    behavior: prefersReducedMotion()
      ? 'auto'
      : 'smooth',
    block: 'start',
  })
  // Keyboard users continue reading from the heading they chose.
  if (!target.hasAttribute('tabindex'))
    target.setAttribute('tabindex', '-1')
  target.focus({ preventScroll: true })
}
</script>

<template>
  <nav v-if="headings.length > 0" class="manual-toc" :aria-label="t('help.contents')" data-test="toc">
    <v-expansion-panels v-if="mobile" variant="accordion" flat>
      <v-expansion-panel :title="t('help.contents')" class="toc-panel">
        <v-expansion-panel-text>
          <ul class="toc-list">
            <li v-for="heading in headings" :key="heading.anchor" :class="`toc-level-${heading.level}`">
              <a :href="`#${heading.anchor}`" class="toc-link" @click.prevent="go(heading.anchor)">{{ heading.text }}</a>
            </li>
          </ul>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
    <template v-else>
      <p class="toc-title text-overline">
        {{ t('help.contents') }}
      </p>
      <ul class="toc-list">
        <li v-for="heading in headings" :key="heading.anchor" :class="`toc-level-${heading.level}`">
          <a :href="`#${heading.anchor}`" class="toc-link" @click.prevent="go(heading.anchor)">{{ heading.text }}</a>
        </li>
      </ul>
    </template>
  </nav>
</template>

<style scoped>
.toc-title {
  margin-bottom: 4px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.toc-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.toc-list li {
  margin: 2px 0;
}

.toc-level-3 {
  padding-inline-start: 14px;
}

.toc-link {
  display: block;
  padding: 3px 0;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  text-decoration: none;
  overflow-wrap: anywhere;
}

.toc-link:hover,
.toc-link:focus-visible {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.toc-panel {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
}
</style>
