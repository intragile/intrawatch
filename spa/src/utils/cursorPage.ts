import { ApiError } from '../api'

/**
 * Query serializer that appends the page cursor to the request's own query. The list
 * endpoints accept `cursor`, but the generated parameter types do not declare it.
 */
export function withCursor(cursor: string | null): { querySerializer: (query: object | undefined) => string } {
  return {
    querySerializer(query) {
      const search = new URLSearchParams()
      for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== undefined && value !== null && value !== '')
          search.set(key, String(value))
      }
      if (cursor !== null)
        search.set('cursor', cursor)
      return search.toString()
    },
  }
}

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
