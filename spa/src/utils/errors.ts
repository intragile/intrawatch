import { ApiError } from '../api'

/** Normalises anything a call can throw into an `ApiError` for `ProblemAlert`. */
export function asApiError(e: unknown): ApiError {
  return e instanceof ApiError
    ? e
    : ApiError.network(e)
}
