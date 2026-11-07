import type { Ref } from 'vue'
import { ref } from 'vue'
import { ApiError } from './problem'

export interface CursorPage<T> {
  data: T[]
  meta: { next_cursor: string | null }
}

export interface CursorList<T> {
  items: Ref<T[]>
  loading: Ref<boolean>
  error: Ref<ApiError | null>
  hasMore: Ref<boolean>
  loadMore: () => Promise<void>
  reload: () => Promise<void>
  reloadLoaded: () => Promise<void>
}

function toApiError(e: unknown): ApiError {
  return e instanceof ApiError
    ? e
    : ApiError.network(e)
}

export function useCursorList<T>(fetchPage: (cursor: string | null) => Promise<CursorPage<T>>): CursorList<T> {
  const items = ref<T[]>([]) as Ref<T[]>
  const loading = ref(false)
  const error = ref<ApiError | null>(null)
  const hasMore = ref(true)

  let cursor: string | null = null
  let pagesLoaded = 0
  // Bumped by every reset so responses of superseded loads are dropped.
  let generation = 0
  let inFlight: Promise<void> | null = null

  function loadMore(): Promise<void> {
    if (inFlight)
      return inFlight
    if (!hasMore.value)
      return Promise.resolve()

    const mine = generation
    loading.value = true
    error.value = null
    const run = (async () => {
      try {
        const page = await fetchPage(cursor)
        if (mine !== generation)
          return
        items.value = [
          ...items.value,
          ...page.data,
        ]
        cursor = page.meta.next_cursor
        hasMore.value = cursor !== null
        pagesLoaded++
      }
      catch (e) {
        if (mine === generation)
          error.value = toApiError(e)
      }
      finally {
        if (mine === generation) {
          loading.value = false
          inFlight = null
        }
      }
    })()
    inFlight = run
    return run
  }

  function reset(): void {
    generation++
    inFlight = null
    items.value = []
    cursor = null
    pagesLoaded = 0
    hasMore.value = true
    error.value = null
  }

  function reload(): Promise<void> {
    reset()
    return loadMore()
  }

  async function reloadLoaded(): Promise<void> {
    const pages = Math.max(pagesLoaded, 1)
    const mine = ++generation
    inFlight = null
    loading.value = true
    error.value = null
    try {
      const fresh: T[] = []
      let next: string | null = null
      let count = 0
      for (let i = 0; i < pages; i++) {
        const page: CursorPage<T> = await fetchPage(next)
        if (mine !== generation)
          return
        fresh.push(...page.data)
        next = page.meta.next_cursor
        count++
        if (next === null)
          break
      }
      items.value = fresh
      cursor = next
      pagesLoaded = count
      hasMore.value = next !== null
    }
    catch (e) {
      if (mine === generation)
        error.value = toApiError(e)
    }
    finally {
      if (mine === generation)
        loading.value = false
    }
  }

  return {
    items,
    loading,
    error,
    hasMore,
    loadMore,
    reload,
    reloadLoaded,
  }
}
