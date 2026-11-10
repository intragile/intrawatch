<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import { prefersReducedMotion } from './manual'

interface Heading {
  anchor: string
  text: string
  level: number
}

const props = defineProps<{
  headings: Heading[]
}>()

/** How far below the viewport top a heading counts as the one being read. */
const SPY_OFFSET = 120

const { t } = useI18n()
// Below md the contents collapse into a panel above the chapter.
const { smAndDown: mobile } = useDisplay()

/** The heading being read: the last one scrolled past the top, or the last one at the end of the page. */
const active = ref<string | null>(null)

function spy(): void {
  const atEnd = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2
    && window.scrollY > 0
  let current: string | null = props.headings[0]?.anchor ?? null
  for (const heading of props.headings) {
    const el = document.getElementById(heading.anchor)
    if (!el)
      continue
    if (atEnd || el.getBoundingClientRect().top <= SPY_OFFSET)
      current = heading.anchor
    else
      break
  }
  active.value = current
}

onMounted(() => {
  window.addEventListener('scroll', spy, { passive: true })
  void nextTick(spy)
})
onUnmounted(() => window.removeEventListener('scroll', spy))
// A new chapter brings new headings (rendered in the same tick).
watch(() => props.headings, () => void nextTick(spy))

/** Scrolls to a heading without touching the route (a hash would be read as a navigation). */
function go(anchor: string): void {
  const target = document.getElementById(anchor)
  if (!target)
    return
  active.value = anchor
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
              <a
                :href="`#${heading.anchor}`"
                class="toc-link"
                :class="{ 'toc-link--active': active === heading.anchor }"
                :aria-current="active === heading.anchor ? 'true' : undefined"
                @click.prevent="go(heading.anchor)"
              >{{ heading.text }}</a>
            </li>
          </ul>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
    <template v-else>
      <p class="toc-title">
        {{ t('help.contents') }}
      </p>
      <ul class="toc-list">
        <li v-for="heading in headings" :key="heading.anchor" :class="`toc-level-${heading.level}`">
          <a
            :href="`#${heading.anchor}`"
            class="toc-link"
            :class="{ 'toc-link--active': active === heading.anchor }"
            :aria-current="active === heading.anchor ? 'true' : undefined"
            @click.prevent="go(heading.anchor)"
          >{{ heading.text }}</a>
        </li>
      </ul>
    </template>
  </nav>
</template>

<style scoped>
.toc-title {
  margin: 0;
  padding: 0 12px 8px;
  font-size: 0.8125rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.toc-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.toc-link {
  display: block;
  padding: 6px 12px;
  border-inline-start: 2px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.7));
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  text-decoration: none;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.toc-level-3 .toc-link {
  padding-inline-start: 26px;
  font-size: 0.9375rem;
}

.toc-link:hover {
  color: rgb(var(--v-theme-on-surface));
  text-decoration: underline;
}

.toc-link:focus-visible {
  outline-offset: -2px;
}

.toc-link--active {
  border-inline-start-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-surface));
  font-weight: 600;
}

.toc-panel {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}
</style>
