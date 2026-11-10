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

/** Default page cap of `loadAll`. */
export const LOAD_ALL_PAGES = 5

/**
 * Follows the cursor through the pages, up to `maxPages`, and returns the rows in order.
 * Meant for short lists a screen needs whole, such as tag and channel options.
 */
export async function loadAll<T>(
  fetchPage: (cursor: string | undefined) => Promise<{ data: T[], meta: { next_cursor: string | null } }>,
  maxPages: number = LOAD_ALL_PAGES,
): Promise<T[]> {
  const out: T[] = []
  let cursor: string | undefined
  for (let i = 0; i < maxPages; i++) {
    const page = await fetchPage(cursor)
    out.push(...page.data)
    if (page.meta.next_cursor === null)
      break
    cursor = page.meta.next_cursor
  }
  return out
}
