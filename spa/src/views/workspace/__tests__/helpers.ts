import type { VueWrapper } from '@vue/test-utils'
import type { ComponentPublicInstance } from 'vue'

/** Emits a value-change from the Vuetify control carrying the given `data-test`. */
export function choose(wrapper: VueWrapper, dataTest: string, value: unknown): void {
  const control = wrapper.findComponent<ComponentPublicInstance>(`[data-test="${dataTest}"]`)
  if (!control.exists())
    throw new Error(`No component for data-test=${dataTest}`)
  control.vm.$emit('update:modelValue', value)
}

export function page<T>(data: T[], next: string | null = null) {
  return {
    data,
    meta: { next_cursor: next, limit: 50 },
  }
}
