import { ApiError } from '../api'

/**
 * The body of an `openapi-fetch` result whose payload has no `{data}` envelope (list
 * pages and the token create response), or its `ApiError`.
 */
export async function bodyOf<T>(request: Promise<{ data?: T, error?: unknown }>): Promise<T> {
  const result = await request
  if (result.error !== undefined && result.error !== null) {
    throw result.error instanceof Error
      ? result.error
      : new ApiError(500, 'urn:client:unexpected-response', 'Unexpected API response')
  }
  if (result.data === undefined)
    throw new ApiError(500, 'urn:client:unexpected-response', 'Unexpected API response')
  return result.data
}
