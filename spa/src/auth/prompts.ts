import type { Ref } from 'vue'
import { ref } from 'vue'
import { setAuthHandlers } from '../api'

/** A modal question the API layer can ask while a request waits for the answer. */
export interface Prompt {
  open: Ref<boolean>
  /** Opens the prompt; concurrent callers share the same answer. */
  request: () => Promise<boolean>
  settle: (ok: boolean) => void
}

function createPrompt(): Prompt {
  const open = ref(false)
  let pending: Promise<boolean> | null = null
  let resolve: ((ok: boolean) => void) | null = null

  return {
    open,
    request() {
      if (!pending) {
        pending = new Promise<boolean>((r) => {
          resolve = r
        })
        open.value = true
      }
      return pending
    },
    settle(ok) {
      const done = resolve
      pending = null
      resolve = null
      open.value = false
      done?.(ok)
    },
  }
}

/** Answered by `ReloginDialog` when a request meets an expired session (401). */
export const reloginPrompt = createPrompt()
/** Answered by `PasswordConfirmDialog` when an action needs a recent password (423). */
export const passwordPrompt = createPrompt()

export function installAuthHandlers(): void {
  setAuthHandlers({
    onUnauthenticated: () => reloginPrompt.request(),
    onPasswordConfirmRequired: () => passwordPrompt.request(),
  })
}
