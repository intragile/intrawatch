import type { Ability } from '../api/types'
import { useSession } from '../stores/session'

/**
 * Checks an ability of the caller in the current workspace. This only decides what
 * the screen offers; the API remains the authority and answers 403 otherwise.
 */
export function useCan(): (ability: Ability) => boolean {
  const session = useSession()
  return ability => session.current?.can[ability] === true
}
