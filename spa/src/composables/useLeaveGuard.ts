import type { Ref } from 'vue'
import { ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'

/**
 * Asks before a route change would discard edits. The caller renders a confirm dialog bound to `open`
 * and calls `confirm()` from it; dismissing the dialog cancels the navigation. `allow()` silences the
 * guard once, for the navigation that follows a successful save.
 */
export function useLeaveGuard(dirty: Ref<boolean>) {
  const open = ref(false)
  let settle: ((allow: boolean) => void) | null = null
  let allowed = false

  onBeforeRouteLeave(() => {
    if (allowed || !dirty.value)
      return true
    open.value = true
    return new Promise<boolean>((resolve) => {
      settle = resolve
    })
  })

  function confirm(): void {
    const resolve = settle
    settle = null
    open.value = false
    resolve?.(true)
  }

  watch(open, (isOpen) => {
    if (!isOpen && settle) {
      const resolve = settle
      settle = null
      resolve(false)
    }
  })

  function allow(): void {
    allowed = true
  }

  function disallow(): void {
    allowed = false
  }

  return { open, confirm, allow, disallow }
}
