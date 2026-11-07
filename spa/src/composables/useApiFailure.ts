import type { ApiError } from '../api'
import { useSession } from '../stores/session'
import { asApiError } from '../utils/errors'

/**
 * Normalises a failed write for `ProblemAlert`. A 403 means the caller's permissions
 * changed since the screen loaded, so `/me` is refreshed to hide what is no longer allowed.
 */
export function useApiFailure(): (e: unknown) => ApiError {
  const session = useSession()
  return (e) => {
    const error = asApiError(e)
    if (error.status === 403)
      void session.load().catch(() => {})
    return error
  }
}
