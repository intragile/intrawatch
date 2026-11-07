import { onBeforeUnmount, onMounted } from 'vue'
import { useWorkspaceLive } from '../live/useWorkspaceLive'

/**
 * Runs `refetch` when the window regains focus and after the live connection comes back, so a screen
 * does not stay stale after changes it received no event for. Listeners are removed on unmount.
 */
export function useRefetchOnReturn(refetch: () => void, busy: () => boolean = () => false): void {
  const live = useWorkspaceLive()
  const offResync = live.onResync(refetch)
  function onFocus(): void {
    if (!busy())
      refetch()
  }
  onMounted(() => window.addEventListener('focus', onFocus))
  onBeforeUnmount(() => {
    window.removeEventListener('focus', onFocus)
    offResync()
  })
}
